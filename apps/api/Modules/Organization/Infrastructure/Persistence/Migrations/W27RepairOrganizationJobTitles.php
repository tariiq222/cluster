<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MAX_CODE_LENGTH = 64;

    public function up(): void
    {
        if (! Schema::hasTable('positions')
            || ! Schema::hasTable('job_titles')
            || ! Schema::hasColumn('positions', 'job_title_id')) {
            return;
        }

        DB::transaction(function (): void {
            $titles = DB::table('positions')->pluck('title_ar')
                ->map(static fn (mixed $title): string => trim((string) $title))
                ->filter(static fn (string $title): bool => $title !== '')
                ->uniqueStrict()
                ->values()
                ->all();
            usort($titles, 'strcmp');
            if ($titles === []) {
                return;
            }

            $existingRows = DB::table('job_titles')->orderBy('id')->get();
            $targetByTitle = [];
            foreach ($titles as $title) {
                foreach ($existingRows as $row) {
                    if ((string) $row->title_ar === $title) {
                        $targetByTitle[$title] = (string) $row->id;
                        break;
                    }
                }
                $targetByTitle[$title] ??= $this->uuidv7($title);
            }

            $targetIds = array_values($targetByTitle);
            $usedCodes = [];
            foreach ($existingRows as $row) {
                if (! in_array((string) $row->id, $targetIds, true)) {
                    $usedCodes[strtoupper((string) $row->code)] = true;
                }
            }

            $codesByTitle = [];
            foreach ($titles as $title) {
                $code = $this->collisionSafeCode($title, $usedCodes);
                $usedCodes[strtoupper($code)] = true;
                $codesByTitle[$title] = $code;
            }

            $now = now();
            foreach ($targetByTitle as $title => $id) {
                $existing = $existingRows->first(static fn (object $row): bool => (string) $row->id === $id);
                $temporaryCode = 'W27TMP_'.strtoupper(substr(hash('sha256', $id), 0, 56));
                if ($existing === null) {
                    DB::table('job_titles')->insert([
                        'id' => $id,
                        'code' => $temporaryCode,
                        'title_ar' => $title,
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('job_titles')->where('id', $id)->update([
                        'code' => $temporaryCode,
                        'updated_at' => $now,
                    ]);
                }
            }

            foreach ($targetByTitle as $title => $id) {
                DB::table('job_titles')->where('id', $id)->update([
                    'code' => $codesByTitle[$title],
                    'updated_at' => $now,
                ]);
                $this->positionsWithExactTitle($title)->update([
                    'job_title_id' => $id,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Forward-only data repair. Historical collisions cannot be restored safely.
    }

    /** @param array<string, true> $usedCodes */
    private function collisionSafeCode(string $title, array $usedCodes): string
    {
        $normalizedTitle = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        $ascii = $normalizedTitle;
        if (function_exists('transliterator_transliterate')) {
            $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII', $normalizedTitle);
            if (is_string($transliterated)) {
                $ascii = $transliterated;
            }
        }

        $ascii = preg_replace('/\s+/u', '_', $ascii) ?? $ascii;
        $base = strtoupper(trim(preg_replace('/[^A-Za-z0-9_-]/', '', $ascii) ?? '', '_-'));
        $candidate = substr($base === '' ? 'TITLE' : $base, 0, self::MAX_CODE_LENGTH);
        if ($base !== '' && ! isset($usedCodes[strtoupper($candidate)])) {
            return $candidate;
        }

        $hash = strtoupper(hash('sha256', 'job-title-code:'.$normalizedTitle));
        foreach ([12, 16, 20, 24, 32, 40, 48, 56] as $hashLength) {
            $prefixLength = self::MAX_CODE_LENGTH - $hashLength - 1;
            $hashedCandidate = substr($candidate, 0, $prefixLength).'_'.substr($hash, 0, $hashLength);
            if (! isset($usedCodes[strtoupper($hashedCandidate)])) {
                return $hashedCandidate;
            }
        }

        throw new RuntimeException('organization_job_title_code_collision_unresolved');
    }

    private function positionsWithExactTitle(string $title): Builder
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return DB::table('positions')->whereRaw('BINARY title_ar = BINARY ?', [$title]);
        }

        return DB::table('positions')->whereRaw('title_ar = ? COLLATE BINARY', [$title]);
    }

    private function uuidv7(string $seed): string
    {
        $hash = hash('sha256', 'job-title:'.$seed);
        $bytes = hex2bin(substr($hash, 0, 32));
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
};
