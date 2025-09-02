<?php

declare(strict_types=1);

namespace Hdaklue\Porter\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'porter:install {--force : Overwrite existing files} {--roles : Create default role classes}';

    protected $description = 'Install Porter RBAC package - publish config, run migrations, and create default roles';

    public function handle(): int
    {
        // Prevent installation in production environment
        if (app()->environment('production')) {
            $this->error('❌ Porter installation is not allowed in production environment!');
            $this->info('💡 Please run this command in development or staging environment only.');

            return Command::FAILURE;
        }

        $this->info('🚀 Installing Porter RBAC...');
        $this->newLine();

        // Step 1: Publish config
        $this->publishConfig();

        // Step 2: Publish and run migrations
        $this->publishAndRunMigrations();

        // Step 3: Create Porter directory and optionally default roles
        $this->createPorterDirectory();

        if ($this->option('roles')) {
            $this->createDefaultRoles();
        }

        $this->newLine();
        $this->info('✅ Porter RBAC installed successfully!');
        $this->info('📁 Porter directory created: '.config('porter.directory', app_path('Porter')));
        if ($this->option('roles')) {
            $this->info('🎭 Default roles created in Porter directory');
        }
        $this->info('🔧 Config published to: config/porter.php');
        $this->info('📊 Database migrations completed');

        $this->newLine();
        $this->info('Next steps:');
        $this->info('1. Update your User model to implement AssignableEntity');
        $this->info('2. Add CanBeAssignedToEntity trait to your User model');
        $this->info('3. Update entities to implement RoleableEntity');
        if (! $this->option('roles')) {
            $this->info('4. Create your custom roles using "php artisan porter:create"');
            $this->info('5. Run "php artisan porter:doctor" to validate your setup');
        } else {
            $this->info('4. Run "php artisan porter:doctor" to validate your setup');
        }

        return Command::SUCCESS;
    }

    private function publishConfig(): void
    {
        $this->info('📄 Publishing configuration...');

        $force = $this->option('force');
        $params = ['--provider' => 'Hdaklue\Porter\Providers\PorterServiceProvider', '--tag' => 'porter-config'];

        if ($force) {
            $params['--force'] = true;
        }

        $this->call('vendor:publish', $params);
    }

    private function publishAndRunMigrations(): void
    {
        $this->info('📊 Publishing and running migrations...');

        // Publish migrations
        $force = $this->option('force');
        $params = ['--provider' => 'Hdaklue\Porter\Providers\PorterServiceProvider', '--tag' => 'porter-migrations'];

        if ($force) {
            $params['--force'] = true;
        }

        $this->call('vendor:publish', $params);

        // Run migrations
        if ($this->confirm('Run migrations now?', true)) {
            $this->call('migrate');
        }
    }

    private function createPorterDirectory(): void
    {
        $this->info('📁 Creating Porter directory...');

        $porterDir = config('porter.directory', app_path('Porter'));

        if (! File::exists($porterDir)) {
            File::makeDirectory($porterDir, 0755, true);
            $this->info("✅ Created Porter directory: {$porterDir}");
        } else {
            $this->info("📁 Porter directory already exists: {$porterDir}");
        }

        // Always create BaseRole.php in the Porter directory
        $this->createBaseRoleFile($porterDir);
    }

    private function createDefaultRoles(): void
    {
        $this->info('🎭 Creating default role classes...');

        $porterDir = config('porter.directory', app_path('Porter'));

        $roles = $this->getDefaultRoles();

        foreach ($roles as $role) {
            $this->createRoleFile($role['name'], $role['level'], $role['description'], $porterDir);
        }
    }

    private function getDefaultRoles(): array
    {
        return [
            [
                'name' => 'Admin',
                'level' => 6,
                'description' => 'Full system access with all privileges',
            ],
            [
                'name' => 'Manager',
                'level' => 5,
                'description' => 'Management privileges with team oversight',
            ],
            [
                'name' => 'Editor',
                'level' => 4,
                'description' => 'Content editing and publishing privileges',
            ],
            [
                'name' => 'Contributor',
                'level' => 3,
                'description' => 'Content creation and basic editing privileges',
            ],
            [
                'name' => 'Viewer',
                'level' => 2,
                'description' => 'Read-only access to content and data',
            ],
            [
                'name' => 'Guest',
                'level' => 1,
                'description' => 'Limited access for guest users',
            ],
        ];
    }

    private function createRoleFile(string $name, int $level, string $description, string $directory): void
    {
        $filename = "{$name}.php";
        $filepath = "{$directory}/{$filename}";

        if (File::exists($filepath) && ! $this->option('force')) {
            $this->warn("⚠️  Role {$name} already exists. Use --force to overwrite.");

            return;
        }

        $stub = $this->getRoleStub();
        $namespace = config('porter.namespace', 'App\\Porter');
        $content = str_replace(
            ['{{name}}', '{{level}}', '{{description}}', '{{snake_name}}', '{{namespace}}'],
            [$name, $level, $description, Str::snake($name), $namespace],
            $stub
        );

        File::put($filepath, $content);
        $this->info("✅ Created role: {$name} (Level {$level})");
        $this->info('   🔑 Key: '.$this->generateRoleKey($name));
    }

    private function getRoleStub(): string
    {
        return File::get(__DIR__.'/../../../resources/stubs/role.stub');
    }

    private function generateRoleKey(string $name): string
    {
        $plainKey = Str::snake($name);
        $storage = config('porter.security.key_storage', 'hashed');

        if ($storage === 'hashed') {
            return hash('sha256', $plainKey.config('app.key'));
        }

        return $plainKey;
    }

    private function createBaseRoleFile(string $directory): void
    {
        $baseRoleFile = "{$directory}/BaseRole.php";

        if (File::exists($baseRoleFile) && ! $this->option('force')) {
            $this->info('📄 BaseRole.php already exists');

            return;
        }

        $namespace = config('porter.namespace', 'App\\Porter');
        $baseRoleStub = $this->getBaseRoleStub();
        $content = str_replace('{{namespace}}', $namespace, $baseRoleStub);

        File::put($baseRoleFile, $content);
        $this->info('✅ Created BaseRole.php in Porter directory');
    }

    private function getBaseRoleStub(): string
    {
        return <<<'STUB'
<?php

declare(strict_types=1);

namespace {{namespace}};

use Hdaklue\Porter\Roles\BaseRole as PorterBaseRole;

/**
 * Base class for all application roles.
 * 
 * This class extends Porter's BaseRole and serves as the foundation
 * for all role classes in your application. You can add application-specific
 * methods here that all roles should inherit.
 */
abstract class BaseRole extends PorterBaseRole
{
    // Add application-specific role methods here
}
STUB;
    }
}
