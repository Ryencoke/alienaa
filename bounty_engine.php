<?php
/* bounty_engine.php — pure logic for the Bounty Board (pages/bounties.php).
   No DB, no session, no output — the payout/refund resolution and the post
   validation are exercised by a headless CLI harness. bounties.php wires it to
   credit escrow; pvp.php's Arena settle calls the resolver when a bounty target
   is beaten.

   THE IDEA: put credits on a rival's head. Posting escrows the amount from your
   pocket immediately (like a trade escrow); whoever BEATS that ghost in the
   Arena collects every standing bounty on them. It's a player-funded PvP
   incentive layer that pairs with the PvE Contract Board — and it's economy-
   safe by construction: escrow in, payout out, credits are only ever MOVED,
   never minted, so even collusion just shuffles a player's own credits. */

const BOUNTY_MIN = 100;
const BOUNTY_MAX = 5000000;   // pockets are BIGINT; this is a sane per-bounty ceiling

// Validate a proposed bounty. Returns '' if ok, else an error message.
// $target/$poster are player rows (need 'id' and 'role'); $amount is credits;
// $posterPocket is the poster's current pocket balance.
function bounty_validate(?array $target, int $posterId, int $amount, int $posterPocket): string {
  if (!$target) return 'That ghost is not in the Sprawl.';
  if ((int)$target['id'] === $posterId) return "You can't post a bounty on yourself.";
  $role = $target['role'] ?? 'member';
  if (in_array($role, ['chatmod','moderator','admin','manager'], true)) return "You can't post a bounty on game staff.";
  if ($role === 'banned') return "That account is banned.";
  if ($amount < BOUNTY_MIN) return 'Minimum bounty is ' . number_format(BOUNTY_MIN) . ' credits.';
  if ($amount > BOUNTY_MAX) return 'Maximum bounty is ' . number_format(BOUNTY_MAX) . ' credits.';
  if ($amount > $posterPocket) return 'Not enough credits in your pocket to escrow that bounty.';
  return '';
}

/* Resolve every ACTIVE bounty on a target when that target is beaten in the
   Arena by $killerId. Bounties the killer posted themselves are REFUNDED to
   them (they achieved their own goal); all others PAY OUT to the killer. Every
   active bounty on the target closes on a qualifying beat.
   $bounties: list of ['id'=>int,'poster_id'=>int,'amount'=>int].
   Returns ['payout','paid_ids','refund','refund_ids']. */
function bounty_resolve(array $bounties, int $killerId): array {
  $payout = 0; $paidIds = []; $refund = 0; $refundIds = [];
  foreach ($bounties as $b) {
    $amt = max(0, (int)$b['amount']);
    if ((int)$b['poster_id'] === $killerId) { $refund += $amt; $refundIds[] = (int)$b['id']; }
    else                                    { $payout += $amt; $paidIds[]  = (int)$b['id']; }
  }
  return ['payout' => $payout, 'paid_ids' => $paidIds, 'refund' => $refund, 'refund_ids' => $refundIds];
}
