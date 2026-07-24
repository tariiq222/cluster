<?php

// PHPStan stubs for class_alias declarations in test files that the architecture
// boundary scanner does not see because it only inspects `use` tokens. These
// stubs let PHPStan resolve the test-local class names to their real contracts
// without forcing test files to use `use` statements that the architecture test
// would flag as cross-module imports.

namespace Modules\PlatformSettings\Tests;

if (! class_exists(OperationsAccessDecision::class, false)) {
    class_alias(\Modules\Authorization\Contracts\AccessDecision::class, __NAMESPACE__.'\\OperationsAccessDecision');
}
if (! class_exists(OperationsDecideAccess::class, false)) {
    class_alias(\Modules\Authorization\Contracts\DecideAccess::class, __NAMESPACE__.'\\OperationsDecideAccess');
}
if (! class_exists(OperationsRecordFacts::class, false)) {
    class_alias(\Modules\Authorization\Contracts\RecordFacts::class, __NAMESPACE__.'\\OperationsRecordFacts');
}
if (! class_exists(OperationsResolvePrincipal::class, false)) {
    class_alias(\Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal::class, __NAMESPACE__.'\\OperationsResolvePrincipal');
}
if (! class_exists(AccessDecision::class, false)) {
    class_alias(\Modules\Authorization\Contracts\AccessDecision::class, __NAMESPACE__.'\\AccessDecision');
}
if (! class_exists(DecideAccess::class, false)) {
    class_alias(\Modules\Authorization\Contracts\DecideAccess::class, __NAMESPACE__.'\\DecideAccess');
}
if (! class_exists(RecordFacts::class, false)) {
    class_alias(\Modules\Authorization\Contracts\RecordFacts::class, __NAMESPACE__.'\\RecordFacts');
}
if (! class_exists(BootstrapOperationsOffice::class, false)) {
    class_alias(\Modules\Authorization\Features\OperationsOffice\BootstrapOperationsOffice::class, __NAMESPACE__.'\\BootstrapOperationsOffice');
}
if (! class_exists(RbacAbacDecideAccess::class, false)) {
    class_alias(\Modules\Authorization\Infrastructure\RbacAbacDecideAccess::class, __NAMESPACE__.'\\RbacAbacDecideAccess');
}
if (! class_exists(ResolveDevelopmentFixturePrincipal::class, false)) {
    class_alias(\Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal::class, __NAMESPACE__.'\\ResolveDevelopmentFixturePrincipal');
}
if (! class_exists(ResolveOrganizationScopeAncestry::class, false)) {
    class_alias(\Modules\Organization\Contracts\ResolveOrganizationScopeAncestry::class, __NAMESPACE__.'\\ResolveOrganizationScopeAncestry');
}
if (! class_exists(DeferredAccessDecision::class, false)) {
    class_alias(\Modules\Authorization\Contracts\AccessDecision::class, __NAMESPACE__.'\\DeferredAccessDecision');
}
if (! class_exists(DeferredDecideAccess::class, false)) {
    class_alias(\Modules\Authorization\Contracts\DecideAccess::class, __NAMESPACE__.'\\DeferredDecideAccess');
}
if (! class_exists(DeferredRecordFacts::class, false)) {
    class_alias(\Modules\Authorization\Contracts\RecordFacts::class, __NAMESPACE__.'\\DeferredRecordFacts');
}
if (! class_exists(DeferredResolveDevelopmentFixturePrincipal::class, false)) {
    class_alias(\Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal::class, __NAMESPACE__.'\\DeferredResolveDevelopmentFixturePrincipal');
}
