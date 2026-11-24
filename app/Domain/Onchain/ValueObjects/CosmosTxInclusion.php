<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A Cosmos transaction BOTH proof endpoints confirm was included with code 0
 * at the same committed height and block time, with byte-identical bytes.
 *
 * It proves inclusion only, never key control. The first five fields are
 * exactly the inputs of bcc-core
 * `CosmosTxVerifier::verifySelfSend($txRaw, $expectedHashHex, $chainId,
 * $accountNumber, $operatorAddress)`, which binds the bytes to the hash and
 * checks the signature locally. `height` and `blockTime` are for the claim
 * (the challenge window), which the verifier does not read.
 */
final class CosmosTxInclusion
{
    /**
     * @param string $txRaw           the raw TxRaw BYTES (binary), as both endpoints served them
     * @param string $txHash          the submitted hash, 64 uppercase hex
     * @param string $chainId         the network id BCC pins for the chain (never an endpoint's word)
     * @param int    $accountNumber   the operator account's number, from the chain's LCD
     * @param string $operatorAddress the validator's valoper (bech32), as the claim named it
     * @param int    $height          the committed height both endpoints report
     * @param int    $blockTime       that block's header time, unix seconds UTC, equal on both
     * @param string $accountPrefix   the account prefix BCC pins for the chain; the signer's
     *                                address must carry it (the claim checks the verdict's from-address)
     */
    public function __construct(
        public readonly string $chainSlug,
        public readonly string $txRaw,
        public readonly string $txHash,
        public readonly string $chainId,
        public readonly int $accountNumber,
        public readonly string $operatorAddress,
        public readonly int $height,
        public readonly int $blockTime,
        public readonly string $accountPrefix,
    ) {
    }
}
