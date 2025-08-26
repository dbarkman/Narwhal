<?php

namespace App\Console\Commands;

use App\Models\EggMetadata;
use App\Services\Pterodactyl\PterodactylClient;
use Illuminate\Console\Command;

class PterodactylProvisionServer extends Command
{
    protected $signature = 'pterodactyl:provision-server {--dry-run}';
    protected $description = 'Interactively provision a server on Pterodactyl for testing';

    public function handle(PterodactylClient $ptero): int
    {
        $firstName = $this->ask('User first name');
        $lastName = $this->ask('User last name');
        $email = $this->ask('User email');

        $serverName = $this->ask('Server name');

        $nestId = (int) $this->ask('Nest ID');
        $eggId = (int) $this->ask('Egg ID');
        $nodeId = (int) $this->ask('Node ID');

        $memory = (int) $this->ask('Memory (MB)');
        $swap = (int) $this->ask('Swap (MB)');
        $disk = (int) $this->ask('Disk (MB)');
        $cpu = (int) $this->ask('CPU (%)');
        $io = 500;

        $databases = (int) $this->ask('Feature limit: databases', 1);
        $allocations = (int) $this->ask('Feature limit: allocations', 1);
        $backups = (int) $this->ask('Feature limit: backups', 1);

        $eggMeta = EggMetadata::where('nest_id', $nestId)
            ->where('egg_id', $eggId)
            ->where('is_active', true)
            ->first();

        if (!$eggMeta) {
            $this->error('Missing per-egg metadata. Please insert environment and port range for this egg first.');
            return self::FAILURE;
        }

        $eggDetails = $ptero->getEggDetails($nestId, $eggId);
        $startup = $eggDetails['startup'] ?? null;
        $dockerImage = $eggDetails['docker_image'] ?? null;

        if (!$startup || !$dockerImage) {
            $this->error('Egg details missing startup or docker_image; cannot proceed.');
            return self::FAILURE;
        }

        $allocationId = $ptero->findFirstFreeAllocationInRange($nodeId, (int) $eggMeta->port_min, (int) $eggMeta->port_max);
        if (!$allocationId) {
            $this->error('No free allocation found on the node within the egg\'s port range.');
            return self::FAILURE;
        }

        $environment = $eggMeta->environment_json ?? [];
        if (!is_array($environment) || empty($environment)) {
            $this->error('Egg environment is empty; provisioning would fail.');
            return self::FAILURE;
        }

        // Resolve existing user id for review (no changes yet)
        $existingUserId = $ptero->findUserByEmail($email);

        $this->line('Review:');
        if (!$existingUserId) {
            $this->info('User not found; a new user will be created with:');
            // Mirror client formatting so the review matches the eventual request
            $formattedUsername = (new \ReflectionClass($ptero))->getMethod('createUser')->isPublic() ? str_replace('@', '_', $email) : str_replace('@', '_', $email);
            $userCreatePayload = [
                'email' => $email,
                'username' => $formattedUsername,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];
            $this->output->writeln(json_encode($userCreatePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('User found: id = ' . $existingUserId);
        }

        $serverPayload = [
            'name' => $serverName,
            'user' => $existingUserId ?: '(to be created)',
            'egg' => $eggId,
            'docker_image' => $dockerImage,
            'startup' => $startup,
            'environment' => $environment,
            'limits' => [
                'memory' => $memory,
                'swap' => $swap,
                'disk' => $disk,
                'io' => $io,
                'cpu' => $cpu,
            ],
            'feature_limits' => [
                'databases' => $databases,
                'allocations' => $allocations,
                'backups' => $backups,
            ],
            'allocation' => [
                'default' => $allocationId,
            ],
        ];

        $this->info('Server create payload:');
        $this->output->writeln(json_encode($serverPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (!$this->option('dry-run')) {
            if (!$this->confirm('Proceed with provisioning?', true)) {
                $this->warn('Aborted by user.');
                return self::SUCCESS;
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Dry-run complete.');
            return self::SUCCESS;
        }

        $userId = $existingUserId ?? $ptero->createUser($email, $firstName, $lastName);

        // Final payload with concrete user id
        $serverPayload['user'] = $userId;
        $result = $ptero->createServer($serverPayload);

        $this->info('Provisioned successfully. Response:');
        $this->output->writeln(json_encode($result, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}


