<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ScaffoldModelPolicies extends Command
{
    protected $signature = 'policies:scaffold-models {--force : Overwrite existing policy files}';

    protected $description = 'Create policy classes for all models';

    public function handle(): int
    {
        $modelFiles = File::files(app_path('Models'));
        $generated = 0;
        $skipped = 0;

        foreach ($modelFiles as $modelFile) {
            $model = $modelFile->getBasename('.php');
            $policy = app_path('Policies/'.$model.'Policy.php');

            if (File::exists($policy) && ! $this->option('force')) {
                $skipped++;
                continue;
            }

            $content = $this->template($model);
            File::ensureDirectoryExists(app_path('Policies'));
            File::put($policy, $content);
            $generated++;
        }

        $this->info("Generated {$generated} policy files, skipped {$skipped}.");

        return self::SUCCESS;
    }

    private function template(string $model): string
    {
        $custom = $this->customMethods($model);

        return "<?php\n\nnamespace App\\Policies;\n\nclass {$model}Policy extends BaseModelPolicy\n{\n{$custom}}\n";
    }

    private function customMethods(string $model): string
    {
        return match ($model) {
            'StockTransfer' => $this->method('approve').$this->method('issue').$this->method('receive'),
            'GoodsReceipt' => $this->method('post'),
            'SalesInvoice' => $this->method('post').$this->method('print').$this->method('activateInstallment'),
            'ReturnOrder' => $this->method('approve'),
            'InstallmentPlan' => $this->method('reschedule'),
            'PaymentMethod' => $this->method('toggleActive'),
            default => '',
        };
    }

    private function method(string $name): string
    {
        $ability = Str::snake($name, '-');

        return "    public function {$name}(\\App\\Models\\User \$user): bool\n    {\n        return \$this->allows(\$user, '{$ability}');\n    }\n\n";
    }
}
