<?php

declare(strict_types=1);

namespace Modules\Documents\Application;

use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;
use Throwable;

/**
 * Routes a link source reference to the producer module's own facts
 * implementation. Producer modules register their LinkedResourceAuthorizationFacts
 * implementations under the 'documents.linked_resource_facts' container tag:
 *
 *     $this->app->tag(MyFacts::class, 'documents.linked_resource_facts');
 *
 * The first non-null result wins; a throwing provider is skipped so one
 * misbehaving module cannot break every document link.
 */
final class RegisteredLinkedResourceAuthorizationFacts implements LinkedResourceAuthorizationFacts
{
    /** @param iterable<LinkedResourceAuthorizationFacts> $providers */
    public function __construct(private readonly iterable $providers = []) {}

    public function resolve(DocumentSourceReference $reference): ?RecordFacts
    {
        foreach ($this->providers as $provider) {
            try {
                $facts = $provider->resolve($reference);
                if ($facts !== null) {
                    return $facts;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
