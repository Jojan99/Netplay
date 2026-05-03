<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

class TestLogoUpload extends Command
{
    protected $signature = 'test:logo-upload {imagePath} {companyId=1}';
    protected $description = 'Test logo upload as base64';

    public function handle()
    {
        $imagePath = $this->argument('imagePath');
        $companyId = $this->argument('companyId');

        if (!file_exists($imagePath)) {
            $this->error("Image file not found: $imagePath");
            return 1;
        }

        $company = Company::find($companyId);
        if (!$company) {
            $this->error("Company not found with ID: $companyId");
            return 1;
        }

        $fileContent = file_get_contents($imagePath);
        $mimeType = mime_content_type($imagePath) ?? 'image/png';
        $base64Logo = "data:{$mimeType};base64," . base64_encode($fileContent);

        $company->update([
            'invoice_logo_url' => $base64Logo,
            'invoice_logo_base64' => $base64Logo,
        ]);

        $this->info("✅ Logo uploaded successfully!");
        $this->info("Company: {$company->name}");
        $this->info("MIME Type: $mimeType");
        $this->info("Base64 length: " . strlen($base64Logo));
        
        return 0;
    }
}
