<?php

declare(strict_types=1);

use App\Commands\CopyCommand;
use App\Services\TransferService;

/**
 * Shared transfer options read their defaults from config/storage.php when the
 * flag is absent. configure() runs in the command constructor, so each case
 * builds a fresh command after setting the config.
 */
function acl_option_default(mixed $configured): mixed
{
    config(['storage.defaults.acl' => $configured]);

    $command = new CopyCommand(app(TransferService::class));

    return $command->getDefinition()->getOption('acl')->getDefault();
}

test('the --acl option falls back to the configured default', function () {
    expect(acl_option_default('public'))->toBe('public')
        ->and(acl_option_default('private'))->toBe('private');
});

test('no default acl is applied when none is configured', function () {
    // Null hands the decision to the driver, which then uses the ACL the
    // destination remote itself is configured with.
    expect(acl_option_default(null))->toBeNull();
});

test('transfers and retries read their defaults the same way', function () {
    config(['storage.defaults.transfers' => 4, 'storage.defaults.retries' => 9]);

    $definition = (new CopyCommand(app(TransferService::class)))->getDefinition();

    expect($definition->getOption('transfers')->getDefault())->toBe('4')
        ->and($definition->getOption('retries')->getDefault())->toBe('9');
});
