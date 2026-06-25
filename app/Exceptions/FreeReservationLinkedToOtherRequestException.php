<?php

namespace App\Exceptions;

use RuntimeException;

class FreeReservationLinkedToOtherRequestException extends RuntimeException
{
    public function __construct(int $otherRequestId)
    {
        parent::__construct(
            'Besplatna rezervacija je već povezana sa drugim zahtjevom (#'.$otherRequestId.').'
        );
    }
}
