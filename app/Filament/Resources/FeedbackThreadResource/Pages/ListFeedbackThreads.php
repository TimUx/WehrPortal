<?php

namespace App\Filament\Resources\FeedbackThreadResource\Pages;

use App\Filament\Resources\FeedbackThreadResource;
use Filament\Resources\Pages\ListRecords;

class ListFeedbackThreads extends ListRecords
{
    protected static string $resource = FeedbackThreadResource::class;
}
