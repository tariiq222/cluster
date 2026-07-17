#!/usr/bin/env ruby
# frozen_string_literal: true

require "yaml"

config = YAML.load_file(".gitlab-ci.yml")
stages = %w[validate build test verify release-build release-artifacts release-sign release-verify]
abort("release stages are unsafe or out of order") unless config["stages"] == stages

required_jobs = %w[
  validate-docs build-docs validate-mermaid test-api test-web test-e2e-w1-1
  verify-boundaries verify-ci-config release-build-images release-sbom-provenance
  release-sign-verify verify-build
]
abort("missing required CI job") unless (required_jobs - config.keys).empty?

config.each do |name, job|
  next unless job.is_a?(Hash) && job["image"]

  image = job["image"].to_s
  unless image.match?(%r!^registry\.internal/third-health-cluster/[a-z0-9-]+@sha256:[0-9a-f]{64}$!)
    abort("#{name} image must be a literal immutable internal digest reference")
  end
  abort("#{name} image must not be variable-driven") if image.include?("$")
end

service = config.fetch("release-build-images").fetch("services").first.fetch("name")
unless service.match?(%r!^registry\.internal/third-health-cluster/[a-z0-9-]+@sha256:[0-9a-f]{64}$!)
  abort("dind service must be literal digest-pinned")
end

release_jobs = %w[release-build-images release-sbom-provenance release-sign-verify verify-build]
protected_tag_rule = '$CI_COMMIT_REF_PROTECTED == "true" && $CI_COMMIT_TAG'
release_environments = {
  "release-build-images" => "release-artifacts",
  "release-sbom-provenance" => "release-artifacts",
  "release-sign-verify" => "release-signing",
  "verify-build" => "release-verification"
}
release_environments.each do |name, environment|
  job = config.fetch(name)
  rules = Array(job["rules"])
  unless rules.any? { |rule| rule["if"] == protected_tag_rule } && rules.any? { |rule| rule["when"] == "never" }
    abort("#{name} must require protected tag rule")
  end
  unless Array(job["extends"]).include?(".supply-chain-job")
    abort("#{name} must directly extend the guarded supply-chain job")
  end
  unless job.dig("environment", "name") == environment && Array(job["tags"]) == [environment]
    abort("#{name} must use isolated #{environment} environment and runner tag")
  end
end

def needs(job)
  Array(job.fetch("needs")).map { |item| item.is_a?(Hash) ? item.fetch("job") : item }
end

required_gates = %w[test-api test-web test-e2e-w1-1 verify-boundaries verify-ci-config]
unless (required_gates - needs(config.fetch("release-build-images"))).empty?
  abort("release build may run before tests or verification")
end
unless (required_gates - needs(config.fetch("release-sign-verify"))).empty?
  abort("release signing may run before tests or verification")
end

build = Array(config.fetch("release-build-images").fetch("script")).join("\n")
unless build.include?("buildx build") && build.include?("metadata-file") && build.include?("containerimage.digest") && !build.include?("RepoDigests")
  abort("image digest must come from deterministic buildx metadata")
end

artifacts = Array(config.fetch("release-sbom-provenance").fetch("script")).join("\n")
unless artifacts.include?("docker login") && artifacts.include?("bind-sbom") && artifacts.include?("scan-sbom") && artifacts.include?("license-policy.json") && artifacts.include?("verify-grype-db") && artifacts.include?("grype db import") && artifacts.include?("grype") && artifacts.include?("generate-manifest") && artifacts.include?("migration-plan") && artifacts.include?("rollback-plan")
  abort("authenticated SBOM, provenance, vulnerability, Grype DB, and license gates are required")
end

image_ref_parser = "python3 scripts/parse_image_refs.py release/image-refs.env"
%w[release-sbom-provenance release-sign-verify].each do |name|
  script = Array(config.fetch(name).fetch("script")).join("\n")
  abort("#{name} must parse image refs without sourcing artifact content") if script.include?(". release/image-refs.env") || !script.include?(image_ref_parser)
end
abort("image reference parser is missing") unless File.file?("scripts/parse_image_refs.py")

%w[release-build-images release-sbom-provenance].each do |name|
  script = Array(config.fetch(name).fetch("script")).join("\n")
  abort("#{name} must reject an exposed signing private key") unless script.include?("must not receive COSIGN_PRIVATE_KEY")
end

signing = Array(config.fetch("release-sign-verify").fetch("script")).join("\n")
unless signing.include?("COSIGN_PRIVATE_KEY") && signing.include?("COSIGN_PUBLIC_KEY") && signing.include?("sign-blob") && signing.include?("verify-blob") && signing.include?("generate-descriptor")
  abort("signing must bind and verify the manifest with public-key verification")
end

verification = Array(config.fetch("verify-build").fetch("script")).join("\n")
unless verification.include?("COSIGN_PUBLIC_KEY") && verification.include?("COSIGN_VERSION") && verification.include?("make verify-build") && verification.include?("must not receive COSIGN_PRIVATE_KEY") && !verification.include?("--key \"$COSIGN_PRIVATE_KEY\"")
  abort("independent verify must use only the public key and make verify-build")
end

tool_checks = Array(config.fetch(".supply-chain-job").fetch("before_script")).join("\n")
exact_tool_checks = {
  "SC_COSIGN_VERSION" => 'test "$(cosign version',
  "SC_SYFT_VERSION" => 'test "$(syft version',
  "SC_GRYPE_VERSION" => 'test "$(grype version'
}
exact_tool_checks.each do |variable, command|
  unless tool_checks.include?(command) && tool_checks.include?("= \"$#{variable}\"")
    abort("#{variable} is not exact-version-verified")
  end
end
abort("legacy shared COSIGN_KEY is forbidden") if File.read(".gitlab-ci.yml").include?("COSIGN_KEY")

puts "PASS: CI configuration enforces literal runner digests, protected release gates, and independent public-key verification"
