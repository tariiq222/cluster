<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_titles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 96)->unique();
            $table->string('title_ar', 255);
            $table->string('status', 16)->default('active')->index();
            $table->timestamps();

            $table->index(['status', 'title_ar']);
        });

        // Backfill distinct titles from existing positions (idempotent: insertIgnore on unique code).
        $this->backfillJobTitles();

        Schema::table('positions', function (Blueprint $table): void {
            $table->foreignUuid('job_title_id')->nullable()->after('title_ar')
                ->constrained('job_titles')->restrictOnDelete();
            $table->index(['job_title_id']);
        });

        // Link existing positions to their matching job_title row by title_ar (works on MySQL and SQLite).
        DB::statement(<<<'SQL'
            UPDATE positions
            SET job_title_id = (
                SELECT id FROM job_titles WHERE job_titles.title_ar = positions.title_ar LIMIT 1
            )
            WHERE job_title_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropForeign(['job_title_id']);
            $table->dropIndex(['job_title_id']);
            $table->dropColumn('job_title_id');
        });
        Schema::dropIfExists('job_titles');
    }

    private function backfillJobTitles(): void
    {
        $titles = DB::table('positions')
            ->whereNotNull('title_ar')
            ->where('title_ar', '!=', '')
            ->distinct()
            ->orderBy('title_ar')
            ->pluck('title_ar');

        $now = now();
        $rows = [];
        foreach ($titles as $title) {
            $rows[] = [
                'id' => $this->uuidv7((string) $title),
                'code' => $this->slugify((string) $title),
                'title_ar' => (string) $title,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows === []) {
            return;
        }
        DB::table('job_titles')->insertOrIgnore($rows);
    }

    private function slugify(string $title): string
    {
        $ascii = preg_replace('/\s+/u', '_', $title) ?? $title;
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $ascii) ?? '';
        $clean = strtoupper(trim($clean, '_-'));
        if ($clean === '') {
            $clean = 'TITLE';
        }

        return substr($clean, 0, 64);
    }

    private function uuidv7(string $seed): string
    {
        // Deterministic UUIDv7 from the title so backfill is repeatable and idempotent.
        $hash = hash('sha256', 'job-title:'.$seed);
        $bytes = hex2bin(substr($hash, 0, 32));
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
};
