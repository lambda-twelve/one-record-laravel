<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LambdaTwelve\OneRecord\Laravel\Storage\Database\Tables;

/*
 * Outbox outcomes are recorded under a lease (a token minted by the claim),
 * so a worker whose lease expired cannot overwrite what the worker now
 * holding the row records. A forward migration rather than a change to the
 * table-creation one: a database migrated before this column existed keeps
 * its rows and gains the column; a fresh database runs both.
 */
return new class extends Migration {
    public function getConnection(): ?string
    {
        $connection = config('one-record.storage.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $outbox = app(Tables::class)->outbox();
        if ($schema->hasColumn($outbox, 'lease_token')) {
            return;
        }
        $schema->table($outbox, static function (Blueprint $table): void {
            $table->char('lease_token', 32)->nullable()->after('attempts');   // the claim that may record this attempt's outcome
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $outbox = app(Tables::class)->outbox();
        if (!$schema->hasColumn($outbox, 'lease_token')) {
            return;
        }
        $schema->table($outbox, static function (Blueprint $table): void {
            $table->dropColumn('lease_token');
        });
    }
};
