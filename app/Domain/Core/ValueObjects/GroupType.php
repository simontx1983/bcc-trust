<?php
/**
 * Group type discriminator.
 *
 * @package BCC\Trust\Core\ValueObjects
 */

namespace BCC\Trust\Core\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

enum GroupType: string {
    case System = 'system';
    case Nft    = 'nft';
    case Local  = 'local';
    case User   = 'user';
}
