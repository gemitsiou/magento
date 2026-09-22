<?php
declare(strict_types=1);

namespace Ced\VivaPayments\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\ValidatorException;

/**
 * Backend model for validating hex color configuration values.
 *
 * Accepts 3 or 6 character hex codes, with or without the leading '#'.
 * Strips the '#' prefix before saving so the value is ready for use
 * in the Viva Wallet checkout redirect URL (&color={hex}).
 */
class HexColor extends Value
{
    /**
     * Validate and normalize the hex color value before saving.
     *
     * @return $this
     * @throws ValidatorException
     */
    public function beforeSave(): self
    {
        $value = trim((string) $this->getValue());

        if ($value === '') {
            $this->setValue('');
            parent::beforeSave();
            return $this;
        }

        // Strip leading '#' if present
        $hex = ltrim($value, '#');

        // Validate: must be exactly 3 or 6 hex characters
        if (!preg_match('/^[A-Fa-f0-9]{3}([A-Fa-f0-9]{3})?$/', $hex)) {
            throw new ValidatorException(
                __('Invalid color code. Please enter a valid hex color (e.g. ff5a00 or #ff5a00).')
            );
        }

        // Store without '#' — Viva expects the raw hex in the URL parameter
        $this->setValue($hex);

        parent::beforeSave();
        return $this;
    }
}
