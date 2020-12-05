<?php

namespace LdapRecord\Laravel\Commands;

use LdapRecord\Models\Entry;
use Illuminate\Console\Command;
use LdapRecord\Models\Attributes\DistinguishedName;

class BrowseLdapServer extends Command
{
    const OPERATION_INSPECT_OBJECT = 'inspect';
    const OPERATION_NAVIGATE_DOWN = 'down';
    const OPERATION_NAVIGATE_UP = 'up';
    const OPERATION_NAVIGATE_TO = 'to';
    const OPERATION_NAVIGATE_TO_ROOT = 'root';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ldap:browse {connection=default : The LDAP connection to browse.}';

    /**
     * The LDAP connections base DN (root).
     *
     * @var string
     */
    protected $baseDn;

    /**
     * The currently selected DN.
     *
     * @var string
     */
    protected $selectedDn;

    /**
     * Execute the command.
     *
     * @return void
     */
    public function handle()
    {
        $this->baseDn = $this->newLdapQuery()->getDn();

        $this->selectedDn = $this->baseDn;

        $this->askForOperation();
    }

    /**
     * Ask the developer for an operation to perform.
     *
     * @param string $prompt
     *
     * @return void
     */
    protected function askForOperation($prompt = 'Select operation')
    {
        $this->info("Viewing object [$this->selectedDn]");

        $operations = $this->getOperations();

        // If the base DN is equal to the currently selected DN, the
        // developer cannot navigate up any further. We'll remove
        // the operation from selection to prevent this.
        if ($this->selectedDn === $this->baseDn) {
            unset($operations[static::OPERATION_NAVIGATE_UP]);
        }

        $this->performOperation($this->choice($prompt, $operations));
    }

    /**
     * Perform the selected operation.
     *
     * @param string $operation
     *
     * @return void
     */
    protected function performOperation($operation)
    {
        $operations = [
            static::OPERATION_INSPECT_OBJECT => function () {
                $this->displayAttributes();
            },
            static::OPERATION_NAVIGATE_UP => function () {
                $this->selectedDn = (new DistinguishedName($this->selectedDn))->parent();

                $this->askForOperation();
            },
            static::OPERATION_NAVIGATE_DOWN => function () {
                $this->displayNestedObjects();

                $this->askForOperation();
            },
            static::OPERATION_NAVIGATE_TO_ROOT => function () {
                $this->selectedDn = $this->baseDn;

                $this->askForOperation();
            },
            static::OPERATION_NAVIGATE_TO => function () {
                $this->selectedDn = $this->ask('Enter the objects distinguished name you would like to navigate to.');

                $this->displayNestedObjects();

                $this->askForOperation();
            }
        ];

        return $operations[$operation]();
    }

    /**
     * Display the nested objects.
     *
     * @return void
     */
    protected function displayNestedObjects()
    {
        $dns = $this->getSelectedNestedDns();

        if (empty($dns)) {
            return $this->askForOperation('This object contains no nested objects. Select operation');
        }

        $dns[static::OPERATION_NAVIGATE_UP] = $this->getOperations()[static::OPERATION_NAVIGATE_UP];

        $selected = $this->choice('Select an object to inspect', $dns);

        if ($selected !== static::OPERATION_NAVIGATE_UP) {
            $this->selectedDn = $dns[$selected];
        }

        $this->askForOperation();
    }

    /**
     * Display the currently selected objects attributes.
     *
     * @return mixed
     */
    protected function displayAttributes()
    {
        $object = $this->newLdapQuery()->find($this->selectedDn);

        $attributes = $object->getAttributes();

        $attributeNames = array_keys($attributes);

        $attribute = $this->choice('Which attribute would you like to view?', $attributeNames);

        $wrapped = array_map([$this, 'wrapAttributeValuesInArray'], $attributes);

        $this->table([$attribute], $wrapped[$attribute]);

        $this->askForOperation();
    }

    /**
     * Wrap attribute values in an array for tabular display.
     *
     * @param array $values
     *
     * @return array[]
     */
    protected function wrapAttributeValuesInArray(array $values)
    {
        return array_map(function ($value) {
            return [$value];
        }, $values);
    }

    /**
     * Get a listing of the nested object DNs inside of the currently selected DN.
     *
     * @return array
     */
    protected function getSelectedNestedDns()
    {
        return $this->newLdapQuery()
            ->in($this->selectedDn)
            ->listing()
            ->paginate()
            ->map(function (Entry $object) {
                return $object->getDn();
            })->toArray();
    }

    /**
     * Get the available command operations.
     *
     * @return array
     */
    protected function getOperations()
    {
        return [
            static::OPERATION_INSPECT_OBJECT => 'View the selected objects attributes',
            static::OPERATION_NAVIGATE_UP => 'Navigate up a level',
            static::OPERATION_NAVIGATE_DOWN => 'Navigate down a level',
            static::OPERATION_NAVIGATE_TO_ROOT => 'Navigate to root',
            static::OPERATION_NAVIGATE_TO => 'Navigate to specific object',
        ];
    }

    /**
     * Create a new LDAP query on the connection.
     *
     * @return \LdapRecord\Query\Model\Builder
     */
    protected function newLdapQuery()
    {
        return Entry::on($this->argument('connection'));
    }
}
