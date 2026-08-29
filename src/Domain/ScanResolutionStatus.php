<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Domain;

enum ScanResolutionStatus: string
{
    case Resolved = 'resolved';
    case Ambiguous = 'ambiguous';
    case Unresolved = 'unresolved';
    case Unsupported = 'unsupported';
}
