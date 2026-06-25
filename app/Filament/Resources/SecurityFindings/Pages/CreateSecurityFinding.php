<?php

namespace App\Filament\Resources\SecurityFindings\Pages;

use App\Filament\Resources\SecurityFindings\SecurityFindingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSecurityFinding extends CreateRecord
{
    protected static string $resource = SecurityFindingResource::class;
}
