#!/usr/bin/env ruby
# frozen_string_literal: true

require "yaml"

workflow_path = ARGV.fetch(0, ".github/workflows/ci.yml")
abort("GitHub Actions workflow is missing") unless File.file?(workflow_path)
abort("legacy GitLab CI configuration must not remain active") if File.exist?(".gitlab-ci.yml")

config = YAML.load_file(workflow_path)
jobs = config.fetch("jobs")
required_jobs = %w[
  validate-docs build-docs validate-mermaid test-api test-web security-quality test-e2e-w1-1
  verify-boundaries verify-ci-config release-build-images release-sbom-provenance
  release-sign-verify verify-build
]
abort("missing required CI job") unless (required_jobs - jobs.keys).empty?

image_pattern = %r!^registry\.internal/third-health-cluster/[a-z0-9-]+@sha256:[0-9a-f]{64}$!
all_zero_digest = "0" * 64
image_digests = []
jobs.each do |name, job|
  image = job.dig("container", "image")
  next unless image

  abort("#{name} image must be a literal immutable internal digest reference") unless image.match?(image_pattern)
  abort("#{name} image must not be variable-driven") if image.include?("$")
  image_digests << image.split("@sha256:", 2).last
end

service = jobs.fetch("release-build-images").fetch("services").fetch("docker").fetch("image")
abort("dind service must be a literal immutable internal digest reference") unless service.match?(image_pattern)
image_digests << service.split("@sha256:", 2).last

using_placeholders = image_digests.all? { |digest| digest == all_zero_digest }
using_approved_digests = image_digests.none? { |digest| digest == all_zero_digest }
unless using_placeholders || using_approved_digests
  abort("CI images must be all placeholders or all approved digests in one protected change")
end

release_jobs = %w[release-build-images release-sbom-provenance release-sign-verify verify-build]
release_environments = {
  "release-build-images" => "release-artifacts",
  "release-sbom-provenance" => "release-artifacts",
  "release-sign-verify" => "release-signing",
  "verify-build" => "release-verification"
}
release_environments.each do |name, environment|
  job = jobs.fetch(name)
  abort("#{name} must require a protected tag") unless job["if"] == "github.ref_type == 'tag' && github.ref_protected"
  abort("#{name} must use protected #{environment} environment") unless job["environment"] == environment
  abort("#{name} must use isolated #{environment} runner label") unless job["runs-on"] == ["self-hosted", environment]
end

abort("workflow must grant only read access to repository contents") unless config["permissions"] == { "contents" => "read" }
jobs.each do |name, job|
  abort("#{name} must not override workflow token permissions") if job.key?("permissions")
end
unless config.dig("env", "SC_LIVE_TOOLING_APPROVED") == "${{ vars.SC_LIVE_TOOLING_APPROVED || 'false' }}"
  abort("workflow must default SC_LIVE_TOOLING_APPROVED to false until the approved repository variable is set")
end
unless config.fetch("env", {}).to_s.scan(/secrets\.([A-Za-z][A-Za-z0-9_]*)/i).empty?
  abort("workflow-level environment must not expose secrets to every job")
end
grype_environment = jobs.fetch("release-sbom-provenance").fetch("env")
expected_grype_variables = {
  "SC_GRYPE_DB_SHA256" => "${{ vars.SC_GRYPE_DB_SHA256 || '' }}",
  "SC_GRYPE_DB_BUILT_AT" => "${{ vars.SC_GRYPE_DB_BUILT_AT || '' }}"
}
unless expected_grype_variables.all? { |name, value| grype_environment[name] == value }
  abort("release artifacts must read Grype DB metadata from its protected environment")
end

def needs(job)
  Array(job.fetch("needs"))
end

required_gates = %w[
  validate-docs build-docs validate-mermaid test-api test-web security-quality
  test-e2e-w1-1 verify-boundaries verify-ci-config
]
unless (required_gates - needs(jobs.fetch("release-build-images"))).empty?
  abort("release build may run before tests or verification")
end
unless (required_gates - needs(jobs.fetch("release-sign-verify"))).empty?
  abort("release signing may run before tests or verification")
end


def commands(job)
  Array(job.fetch("steps")).map { |step| step["run"] if step.is_a?(Hash) }.compact.join("\n")
end

def action_steps(job)
  Array(job.fetch("steps")).map { |step| step["uses"] if step.is_a?(Hash) }.compact
end

trusted_pull_request_condition = "github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository"
jobs.each do |name, job|
  if job["runs-on"] == ["self-hosted", "ci-general"] && job["if"] != trusted_pull_request_condition
    abort("#{name} must not execute fork pull-request code on an internal runner")
  end

  first_step = Array(job.fetch("steps")).first
  guard = first_step.is_a?(Hash) && first_step["name"] == "Require approved internal tooling" && first_step["run"].to_s.include?('test "$SC_LIVE_TOOLING_APPROVED" = "true" || {') && first_step["run"].to_s.include?("exit 1")
  abort("#{name} must fail closed before executing CI commands") unless guard

  action_steps(job).each do |action|
    next if action.start_with?("./")

    abort("#{name} action must be pinned by full commit SHA") unless action.match?(%r!^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+@[0-9a-f]{40}$!)
  end
end

api_quality = commands(jobs.fetch("test-api"))
unless api_quality.include?("test -f apps/api/composer.lock") && api_quality.include?("composer --working-dir=apps/api validate --strict") && api_quality.include?("make lint-api") && api_quality.include?("make analyse-api") && api_quality.include?("composer --working-dir=apps/api audit --locked")
  abort("API CI must enforce its lockfile, formatting, static analysis, and dependency audit")
end

web_quality = commands(jobs.fetch("test-web"))
unless web_quality.include?("npm --prefix apps/web run api:check") && web_quality.include?("npm --prefix apps/web audit")
  abort("web CI must enforce generated API drift and dependency audit")
end

security_quality = commands(jobs.fetch("security-quality"))
abort("CI must scan the repository for secrets") unless security_quality.include?("gitleaks detect --source . --redact --no-banner")

secret_allowlists = {
  "CI_REGISTRY" => %w[release-build-images release-sbom-provenance],
  "CI_REGISTRY_IMAGE" => %w[release-build-images],
  "CI_REGISTRY_USER" => %w[release-build-images release-sbom-provenance],
  "CI_REGISTRY_PASSWORD" => %w[release-build-images release-sbom-provenance],
  "COSIGN_PRIVATE_KEY" => %w[release-sign-verify],
  "COSIGN_PUBLIC_KEY" => %w[release-sign-verify verify-build]
}
jobs.each do |name, job|
  referenced_secrets = job.to_s.scan(/secrets\.([A-Za-z][A-Za-z0-9_]*)/i).flatten.uniq
  referenced_secrets.each do |secret|
    allowed_jobs = secret_allowlists[secret]
    abort("#{name} references unknown secret #{secret}") unless allowed_jobs

    abort("#{name} references a secret outside its protected job allowlist") unless allowed_jobs.include?(name)
  end
end

build = commands(jobs.fetch("release-build-images"))
unless build.include?("docker buildx build") && build.include?("--metadata-file") && build.include?("containerimage.digest") && !build.include?("RepoDigests")
  abort("image digest must come from deterministic buildx metadata")
end

artifacts = commands(jobs.fetch("release-sbom-provenance"))
unless artifacts.include?("docker login") && artifacts.include?("bind-sbom") && artifacts.include?("scan-sbom") && artifacts.include?("license-policy.json") && artifacts.include?("verify-grype-db") && artifacts.include?("grype db import") && artifacts.include?("grype") && artifacts.include?("generate-manifest") && artifacts.include?("migration-plan") && artifacts.include?("rollback-plan")
  abort("authenticated SBOM, provenance, vulnerability, Grype DB, and license gates are required")
end

image_ref_parser = "python3 scripts/parse_image_refs.py release/image-refs.env"
%w[release-sbom-provenance release-sign-verify].each do |name|
  script = commands(jobs.fetch(name))
  abort("#{name} must parse image refs without sourcing artifact content") if script.include?(". release/image-refs.env") || !script.include?(image_ref_parser)
end
abort("image reference parser is missing") unless File.file?("scripts/parse_image_refs.py")

%w[release-build-images release-sbom-provenance].each do |name|
  script = commands(jobs.fetch(name))
  abort("#{name} must reject an exposed signing private key") unless script.include?("must not receive COSIGN_PRIVATE_KEY")
  abort("#{name} must not reference a signing private key secret") if jobs.fetch(name).to_s.include?("secrets.COSIGN_PRIVATE_KEY")
end

signing = commands(jobs.fetch("release-sign-verify"))
unless signing.include?("COSIGN_PRIVATE_KEY") && signing.include?("COSIGN_PUBLIC_KEY") && signing.include?("sign-blob") && signing.include?("verify-blob") && signing.include?("generate-descriptor")
  abort("signing must bind and verify the manifest with public-key verification")
end
abort("signing job must use the protected private key only in its environment") unless jobs.fetch("release-sign-verify").dig("env", "COSIGN_PRIVATE_KEY") == "${{ secrets.COSIGN_PRIVATE_KEY }}"

verification = commands(jobs.fetch("verify-build"))
unless verification.include?("COSIGN_PUBLIC_KEY") && verification.include?("COSIGN_VERSION") && verification.include?("make verify-build") && verification.include?("must not receive COSIGN_PRIVATE_KEY") && !verification.include?("--key \"$COSIGN_PRIVATE_KEY\"")
  abort("independent verify must use only the public key and make verify-build")
end
abort("verification job must not reference a signing private key secret") if jobs.fetch("verify-build").to_s.include?("secrets.COSIGN_PRIVATE_KEY")

release_jobs.each do |name|
  uploads = action_steps(jobs.fetch(name)).grep(%r!^actions/upload-artifact@!)
  abort("#{name} must retain release artifacts for one week") if uploads.empty?
  abort("#{name} must retain release artifacts for one week") unless jobs.fetch(name).to_s.include?("retention-days") && jobs.fetch(name).to_s.include?("7")
end

workflow = File.read(workflow_path)
abort("legacy shared COSIGN_KEY is forbidden") if workflow.include?("COSIGN_KEY")

puts "PASS: GitHub Actions configuration enforces literal runner digests, protected release gates, and independent public-key verification"
