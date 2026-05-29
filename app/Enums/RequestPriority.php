<?php

namespace App\Enums;

enum RequestPriority: string
{
    case NORMAL = 'Normal';
    case URGENT = 'Urgent';
    case CRITICAL = 'Critical';
}
