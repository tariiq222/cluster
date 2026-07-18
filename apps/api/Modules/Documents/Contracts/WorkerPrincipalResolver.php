<?php

namespace Modules\Documents\Contracts;

use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

/**
 * Marker interface that authenticates a worker principal used for the internal
 * scan and reconcile endpoints. The contract is opt-in: only a principal
 * resolver that explicitly implements it may be wired into the internal
 * document actions. User-facing routes must continue to bind
 * {@see ResolveDevelopmentFixturePrincipal}. A worker resolver must therefore
 * remain disjoint from a user-facing resolver.
 */
interface WorkerPrincipalResolver extends ResolveDevelopmentFixturePrincipal {}
