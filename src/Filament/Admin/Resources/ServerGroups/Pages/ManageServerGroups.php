<?php

namespace PelicanServerGroups\Filament\Admin\Resources\ServerGroups\Pages;

use Filament\Resources\Pages\ManageRecords;
use PelicanServerGroups\Filament\Admin\Resources\ServerGroups\ServerGroupResource;

class ManageServerGroups extends ManageRecords
{
    protected static string $resource = ServerGroupResource::class;
}
