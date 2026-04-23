# Migrated from (M1 audit trail)

This plugin was assembled in M1 by merging three predecessor plugins.
Each predecessor directory was deleted in M1.5 — these hashes record
where the code came from in case anyone ever needs to diff against
the pre-merge state.

| Predecessor          | Pre-M1 HEAD                                | Merged in |
|----------------------|--------------------------------------------|-----------|
| bcc-trust-engine     | `79398e49a583054d1c501a94ff35b5390c42e3d2` | M1.1      |
| bcc-onchain-signals  | `43dae7d46e6eb2548e65386d10b08d361a20a052` | M1.2      |
| bcc-disputes         | `33967df5d3f8e6b58806abb9fe5274e52423102f` | M1.3      |

The predecessor plugins were each their own git repository. Those
repositories — along with their full history and `pre-m1` / `m1.N`
tags — were deleted in M1.5 along with the plugin directories. The
commits above are referenced by hash only; there is no remote
carrying them, so they are not recoverable from this checkout. The
full code history lives inside this plugin under
`app/Domain/{Core,Disputes,Onchain}/` with `[M1.N]` commit labels.

## Namespace map

| Before                 | After                       |
|------------------------|-----------------------------|
| `BCC\Trust\*`          | `BCC\Trust\Core\*`          |
| `BCC\Onchain\*`        | `BCC\Trust\Onchain\*`       |
| `BCC\Disputes\*`       | `BCC\Trust\Disputes\*`      |

## Class renames (M1.3)

| Before                        | After                   |
|-------------------------------|-------------------------|
| `ResolveDisputeService`       | `DisputeResolver`       |
| `DisputeAdjudicationService`  | `DisputeAdjudicator`    |