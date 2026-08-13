from __future__ import annotations

import copy
import importlib.util
import sys
import unittest
from pathlib import Path

import yaml


ROOT = Path(__file__).resolve().parents[2]
POLICY_PATH = ROOT / "scripts/production_bundle_policy.py"
SPEC = importlib.util.spec_from_file_location("production_bundle_policy", POLICY_PATH)
assert SPEC is not None and SPEC.loader is not None
POLICY = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = POLICY
SPEC.loader.exec_module(POLICY)


class ProductionBundlePolicyTest(unittest.TestCase):
    def setUp(self) -> None:
        self.compose = yaml.safe_load(
            (ROOT / "infra/platform/production/compose.yaml").read_text(encoding="utf-8")
        )

    def test_missing_documents_runtime_contract_is_rejected(self) -> None:
        document = copy.deepcopy(self.compose)
        environment = document["services"]["api"]["environment"]
        environment.pop("DOCUMENTS_WORKER_TOKEN", None)

        failures = POLICY.validate_compose(document)

        self.assertIn(
            "missing_documents_configuration",
            {failure.code for failure in failures},
        )

    def test_destructive_migration_evidence_is_required(self) -> None:
        document = copy.deepcopy(self.compose)
        environment = document["services"]["api"]["environment"]
        environment.pop("DESTRUCTIVE_MIGRATION_BACKUP_ID", None)

        failures = POLICY.validate_compose(document)

        self.assertIn(
            "missing_destructive_migration_evidence",
            {failure.code for failure in failures},
        )


if __name__ == "__main__":
    unittest.main()
