<?php

namespace LdapRecord\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use LdapRecord\Laravel\Auth\DatabaseUserProvider;
use LdapRecord\Laravel\Auth\UserProvider;
use LdapRecord\Laravel\DetectsSoftDeletes;
use LdapRecord\Laravel\Events\Import\Completed;
use LdapRecord\Laravel\Events\Import\DeletedMissing;
use LdapRecord\Laravel\Events\Import\Imported;
use LdapRecord\Laravel\Events\Import\ImportFailed;
use LdapRecord\Laravel\Events\Import\Started;
use LdapRecord\Models\Collection;
use LdapRecord\Models\Model;
use Symfony\Component\Console\Helper\ProgressBar;

class ImportLdapUsers extends Command
{
    use DetectsSoftDeletes;

    /**
     * The signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ldap:import {provider=ldap : The authentication provider to import.}
            {user? : The specific user to import.}
            {--f|filter= : A raw LDAP filter to apply to the LDAP query.}
            {--s|scopes= : Comma seperated list of scopes to apply to the LDAP query.}
            {--a|attributes= : Comma separated list of LDAP attributes to select.}
            {--d|delete : Enable soft-deleting user models if their LDAP account is disabled.}
            {--r|restore : Enable restoring soft-deleted user models if their LDAP account is enabled.}
            {--c|chunk= : Enable chunked based importing by specifying how many records per chunk.}
            {--dm|delete-missing : Enable soft-deleting all users that are missing from the import.}
            {--no-log : Disable logging successful and unsuccessful imports.}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = "Import LDAP users into the application's database";

    /**
     * The LDAP user import instance.
     */
    protected LdapUserImporter $importer;

    /**
     * The import progress bar indicator.
     */
    protected ?ProgressBar $progress;

    /**
     * Execute the console command.
     *
     * @throws \LdapRecord\Models\ModelNotFoundException
     */
    public function handle(LdapUserImporter $importer, Repository $config): void
    {
        $config->set('ldap.logging.enabled', $this->isLogging());

        /** @var \LdapRecord\Laravel\Auth\DatabaseUserProvider $provider */
        $provider = Auth::createUserProvider($providerName = $this->argument('provider'));

        if (is_null($provider)) {
            $this->error("Provider [{$providerName}] does not exist.");

            return;
        } elseif (! $provider instanceof UserProvider) {
            $this->error("Provider [{$providerName}] is not configured for LDAP authentication.");

            return;
        } elseif (! $provider instanceof DatabaseUserProvider) {
            $this->error("Provider [{$providerName}] is not configured for database synchronization.");

            return;
        }

        $this->registerEventListeners();

        $this->setImporter($importer);

        $this->applyImporterOptions($provider);

        ($perChunk = $this->option('chunk'))
            ? $this->beginChunkedImport($perChunk)
            : $this->beginImport();
    }

    /**
     * Begin importing users into the database.
     */
    protected function beginImport(): void
    {
        $loaded = $this->importer->loadObjectsFromRepository($this->argument('user'));

        if ($loaded->count() === 0) {
            $this->info('There were no users found to import.');

            return;
        } elseif ($loaded->count() === 1) {
            $this->info("Found user [{$loaded->first()->getRdn()}].");
        } else {
            $this->info("Found [{$loaded->count()}] user(s).");
        }

        $this->confirmAndDisplayObjects($loaded);

        $this->confirmAndExecuteImport();
    }

    /**
     * Begin importing users into the database by chunk.
     */
    protected function beginChunkedImport(int $perChunk): void
    {
        $total = 0;

        $this->importer->chunkObjectsFromRepository(function (Collection $objects) use (&$total) {
            $this->info("\nChunking... Found [{$objects->count()}] user(s).");

            $this->confirmAndDisplayObjects($objects);

            $imported = $this->confirmAndExecuteImport();

            $total = $total + $imported;
        }, $perChunk);

        $total
            ? $this->info("\nCompleted chunked import. Successfully imported [{$total}] user(s).")
            : $this->info("\nCompleted chunked import. No users were imported.");
    }

    /**
     * Confirm and execute the import.
     */
    protected function confirmAndExecuteImport(): int
    {
        $imported = 0;

        if (
            ! $this->input->isInteractive()
            || $this->confirm('Would you like these users to be imported / synchronized?', $default = true)
        ) {
            $imported = $this->importer->execute()->count();

            $this->info("\n Successfully imported / synchronized [$imported] user(s).");
        } else {
            $this->info("\n Okay, no users were imported / synchronized.");
        }

        return $imported;
    }

    /**
     * Register the import event callbacks for the command.
     */
    protected function registerEventListeners(): void
    {
        Event::listen(Started::class, function (Started $event) {
            $this->progress = $this->output->createProgressBar($event->objects->count());
        });

        Event::listen(Completed::class, function () {
            if ($this->progress) {
                $this->progress->finish();
            }
        });

        Event::listen(Imported::class, function () {
            if ($this->progress) {
                $this->progress->advance();
            }
        });

        Event::listen(ImportFailed::class, function () {
            if ($this->progress) {
                $this->progress->advance();
            }
        });

        Event::listen(DeletedMissing::class, function (DeletedMissing $event) {
            $event->deleted->isEmpty()
                ? $this->info("\n No missing users found. None have been soft-deleted.")
                : $this->info("\n Successfully soft-deleted [{$event->deleted->count()}] users.");
        });
    }

    /**
     * Displays the given users in a table.
     */
    protected function confirmAndDisplayObjects(Collection $objects): void
    {
        if (! $this->input->isInteractive()) {
            return;
        }

        if (! $this->confirm('Would you like to display the user(s) to be imported / synchronized?', $default = false)) {
            return;
        }

        $rows = $objects->sortBy(function (Model $object) {
            return $object->getName();
        })->map(function (Model $object) {
            return [
                'dn' => $object->getDn(),
                'name' => $object->getRdn(),
            ];
        })->toArray();

        $this->table(['Name', 'Distinguished Name'], $rows);
    }

    /**
     * Apply the import options to the importer.
     */
    protected function applyImporterOptions(DatabaseUserProvider $provider): void
    {
        $this->importer->setLdapUserRepository(
            $provider->getLdapUserRepository()
        );

        $this->importer->setLdapSynchronizer(
            $provider->getLdapUserSynchronizer()
        );

        if ($filter = $this->option('filter')) {
            $this->importer->setLdapRawFilter($filter);
        }

        if ($scopes = $this->option('scopes')) {
            $this->importer->setLdapScopes(explode(',', $scopes));
        }

        if ($attributes = $this->option('attributes')) {
            $this->importer->setLdapRequestAttributes(explode(',', $attributes));
        }

        if ($this->isRestoring()) {
            $this->importer->restoreEnabledUsers();
        }

        if ($this->isDeleting()) {
            $this->importer->trashDisabledUsers();
        }

        if ($this->isDeletingMissing()) {
            $this->importer->enableSoftDeletes();
        }
    }

    /**
     * Set the importer to use.
     */
    protected function setImporter(LdapUserImporter $importer): void
    {
        $this->importer = $importer;
    }

    /**
     * Determine if logging is enabled.
     */
    protected function isLogging(): bool
    {
        return ! $this->option('no-log');
    }

    /**
     * Determine if soft-deleting disabled user accounts is enabled.
     */
    protected function isDeleting(): bool
    {
        return $this->option('delete') == 'true';
    }

    /**
     * Determine if soft-deleting all missing users is enabled.
     */
    protected function isDeletingMissing(): bool
    {
        return $this->option('delete-missing') == 'true' && is_null($this->argument('user'));
    }

    /**
     * Determine if restoring re-enabled users is enabled.
     */
    protected function isRestoring(): bool
    {
        return $this->option('restore') == 'true';
    }
}
