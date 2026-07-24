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
    case System    = 'system';
    case Nft       = 'nft';
    // Union hall — auto-provisioned one-per-chain, open membership
    // (meta kind 'hall', chain tag `_bcc_chain_tag`).
    case Hall      = 'hall';
    case User      = 'user';
    // Validator/delegator community — auto-provisioned per claimed
    // validator, delegation-verified join (meta kind 'delegators').
    case Validator = 'validator';
}
