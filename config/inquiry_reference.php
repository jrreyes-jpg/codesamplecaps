<?php

// Isang format lang ang gamit ng email at Admin pages.
function inquiry_reference_number(int $inquiryId): string
{
    return 'INQ-' . str_pad((string)max(0, $inquiryId), 6, '0', STR_PAD_LEFT);
}

function inquiry_reference_id(string $reference): ?int
{
    if (!preg_match('/^INQ-0*([1-9][0-9]*)$/i', trim($reference), $matches)) {
        return null;
    }

    return (int)$matches[1];
}
