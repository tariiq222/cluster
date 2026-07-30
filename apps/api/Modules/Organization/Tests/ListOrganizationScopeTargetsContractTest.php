<?php

declare(strict_types=1);

namespace Modules\Organization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\ListOrganizationScopeTargets;
use Modules\Organization\Infrastructure\Persistence\DatabaseListOrganizationScopeTargets;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;
use Tests\TestCase;

/**
 * Architectural seam test for the Organization-owned
 * {@see ListOrganizationScopeTargets} contract that the Authorization
 * adapter consumes through
 * {@see \Modules\Authorization\Infrastructure\Persistence\DatabaseListAssignmentScopeTargets}.
 *
 * The contract must stay generic over scope types and never reach into the
 * Authorization module. Pagination is the Authorization adapter's job, so
 * the Organization contract must never accept, emit, or interpret a
 * pagination cursor.
 */
#[CoversClass(ListOrganizationScopeTargets::class)]
#[CoversClass(DatabaseListOrganizationScopeTargets::class)]
final class ListOrganizationScopeTargetsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_interface_signature_is_locked(): void
    {
        $reflection = new ReflectionClass(ListOrganizationScopeTargets::class);

        $this->assertTrue($reflection->isInterface(), 'contract must remain an interface');

        $this->assertTrue($reflection->hasMethod('labelCandidates'), 'contract must expose labelCandidates');

        $method = $reflection->getMethod('labelCandidates');
        $params = $method->getParameters();

        $this->assertCount(3, $params, 'labelCandidates must take exactly 3 parameters');

        $this->assertSame('scopeType', $params[0]->getName());
        $this->assertSame('string', (string) $params[0]->getType());

        $this->assertSame('candidates', $params[1]->getName());
        $this->assertSame('array', (string) $params[1]->getType());

        $this->assertSame('search', $params[2]->getName());
        $this->assertTrue($params[2]->allowsNull(), 'search parameter must be nullable');
        $this->assertSame('?string', (string) $params[2]->getType());

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('array', (string) $returnType);
    }

    public function test_returned_map_is_keyed_by_original_candidate_index(): void
    {
        $clusterId = $this->insertClusterRow('CL-CONTRACT', 'تجمع العقد', 'Contract cluster');
        $facilityA = $this->insertFacilityRow('FA-CONTRACT', 'منشأة العقد أ', 'Contract Facility A', $clusterId);
        $facilityB = $this->insertFacilityRow('FB-CONTRACT', 'منشأة العقد ب', 'Contract Facility B', $clusterId);

        $candidates = [
            ['scope_type' => 'facility', 'scope_id' => $facilityB],
            ['scope_type' => 'cluster', 'scope_id' => $clusterId],
            ['scope_type' => 'facility', 'scope_id' => $facilityA],
        ];

        $impl = new DatabaseListOrganizationScopeTargets;
        $result = $impl->labelCandidates('facility', $candidates, null);

        $this->assertSame(
            [0, 1, 2],
            array_keys($result),
            'map keys must preserve the original candidate list index',
        );

        $this->assertSame('facility', $result[0]['scope_type']);
        $this->assertSame($facilityB, $result[0]['scope_id']);
        $this->assertSame('FB-CONTRACT', $result[0]['code']);

        $this->assertArrayHasKey(1, $result);
        $this->assertSame('cluster', $result[1]['scope_type']);
        $this->assertSame($clusterId, $result[1]['scope_id']);

        $this->assertSame('facility', $result[2]['scope_type']);
        $this->assertSame($facilityA, $result[2]['scope_id']);
        $this->assertSame('FA-CONTRACT', $result[2]['code']);
    }

    public function test_interface_source_imports_zero_modules_authorization_symbols(): void
    {
        $ast = $this->parseAst((new ReflectionClass(ListOrganizationScopeTargets::class))->getFileName());

        $imports = $this->collectFullyQualifiedNames($ast);
        $this->assertSame([], array_values(array_filter($imports, static fn (string $i): bool => str_starts_with($i, 'Modules\\Authorization\\'))), 'interface must declare zero Modules\\Authorization\\* imports');

        foreach ($imports as $import) {
            $this->assertStringStartsNotWith(
                'Modules\\Authorization\\',
                $import,
                "Organization contract must not import Modules\\Authorization\\* (found {$import}).",
            );
        }
    }

    public function test_implementation_source_imports_zero_modules_authorization_symbols(): void
    {
        $ast = $this->parseAst((new ReflectionClass(DatabaseListOrganizationScopeTargets::class))->getFileName());
        $imports = $this->collectFullyQualifiedNames($ast);
        $this->assertSame([], array_values(array_filter($imports, static fn (string $i): bool => str_starts_with($i, 'Modules\\Authorization\\'))), 'implementation must declare zero Modules\\Authorization\\* imports');

        foreach ($imports as $import) {
            $this->assertStringStartsNotWith(
                'Modules\\Authorization\\',
                $import,
                "Organization implementation must not import Modules\\Authorization\\* (found {$import}).",
            );
        }
    }

    public function test_interface_source_references_cursor_zero_times(): void
    {
        $content = file_get_contents((new ReflectionClass(ListOrganizationScopeTargets::class))->getFileName());
        $this->assertNotFalse($content);

        $this->assertCursorMentionsAreZero(
            $content,
            'Organization contract interface must not mention a cursor.',
        );
    }

    public function test_implementation_source_references_cursor_zero_times(): void
    {
        $content = file_get_contents((new ReflectionClass(DatabaseListOrganizationScopeTargets::class))->getFileName());
        $this->assertNotFalse($content);

        $this->assertCursorMentionsAreZero(
            $content,
            'Organization implementation must not mention a cursor.',
        );
    }

    /**
     * @return list<\PhpParser\Node>
     */
    private function parseAst(string $filePath): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $ast = $parser->parse(file_get_contents($filePath));

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $ast = $traverser->traverse($ast);

        return $ast;
    }

    /**
     * @param  list<\PhpParser\Node>  $ast
     * @return list<string>
     */
    private function collectFullyQualifiedNames(array $ast): array
    {
        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var list<string> */
            public array $names = [];

            public function enterNode(\PhpParser\Node $node): null
            {
                if ($node instanceof \PhpParser\Node\Name\FullyQualified) {
                    $this->names[] = $node->toString();
                } elseif ($node instanceof \PhpParser\Node\Stmt\UseUse) {
                    $this->names[] = $node->name->toString();
                }

                return null;
            }
        };
        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return array_values(array_unique($visitor->names));
    }

    private function assertCursorMentionsAreZero(string $content, string $message): void
    {
        $stripped = preg_replace('~//[^\n]*~', '', $content) ?? $content;

        $needle = 'cursor';
        $found = stripos($stripped, $needle);

        $this->assertFalse(
            $found,
            $message.' Found "'.$needle.'" at offset '.$found.'.',
        );
    }

    private function insertClusterRow(string $code, string $nameAr, string $nameEn): string
    {
        $id = '018f6f7d-0c00-7000-8000-00000000cc01';
        DB::table('clusters')->insert([
            'id' => $id,
            'singleton_key' => 0,
            'code' => $code,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertFacilityRow(string $code, string $nameAr, string $nameEn, string $clusterId): string
    {
        $facilityTypeId = $this->upsertLookup('facility_types', 'contract-facility-type', 'منشأة العقد');

        $id = sprintf('018f6f7d-0c00-7000-8000-00000000%04x', random_int(0, 0xFFFF));
        DB::table('facilities')->insert([
            'id' => $id,
            'cluster_id' => $clusterId,
            'facility_type_id' => $facilityTypeId,
            'code' => $code,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function upsertLookup(string $table, string $code, string $nameAr): string
    {
        $existing = DB::table($table)->where('code', $code)->value('id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        $id = sprintf('018f6f7d-0c00-7000-8000-00000000%04x', random_int(0, 0xFFFF));
        if ($table === 'facility_types' || $table === 'unit_types') {
            DB::table($table)->insert([
                'id' => $id,
                'code' => $code,
                'name_ar' => $nameAr,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table($table)->insert([
                'id' => $id,
                'code' => $code,
                'name_ar' => $nameAr,
                'name_en' => null,
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }
}
