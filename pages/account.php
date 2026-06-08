<?php /* pages/account.php — settings hub: appearance + chat */
$pid = $_SESSION['pid'];
$pdo = db();
$msg = '';
$all_themes = themes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'theme') {
    $theme = $_POST['theme'] ?? 'neon';
    if (!isset($all_themes[$theme])) $theme = 'neon';
    $accent = '';
    if (isset($_POST['use_accent']) && preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['accent_color'] ?? '')) {
      $accent = $_POST['accent_color'];
    }
    $pdo->prepare('UPDATE players SET theme = ?, accent_color = ? WHERE id = ?')->execute([$theme, $accent, $pid]);
    $msg = 'Appearance saved.';
    $player = current_player();
  }

  elseif ($action === 'chatcolor') {
    $c = $_POST['chat_color'] ?? '';
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
      $pdo->prepare('UPDATE players SET chat_color = ? WHERE id = ?')->execute([$c, $pid]);
      $msg = 'Chat color updated.';
      $player = current_player();
    } else {
      $msg = 'Pick a valid color.';
    }
  }
}

$role      = $player['role'] ?? 'member';
$curTheme  = $player['theme'] ?? 'neon';
$curAccent = $player['accent_color'] ?? '';
?>
<div class="panel">
  <h2>Account Settings</h2>
  <?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
  <p class="muted">Tune how Sprawl-9 looks and how you show up in chat. Your stats live on the
    <a href="index.php?p=home">Hideout</a>.</p>
</div>

<div class="panel">
  <h3>Appearance</h3>
  <form method="post">
    <input type="hidden" name="action" value="theme">
    <label>Theme</label>
    <p><select name="theme">
      <?php foreach ($all_themes as $key => $t): ?>
        <option value="<?= e($key) ?>" <?= $key === $curTheme ? 'selected' : '' ?>><?= e($t['name']) ?></option>
      <?php endforeach; ?>
    </select></p>
    <label style="display:inline-block">
      <input type="checkbox" name="use_accent" value="1" <?= $curAccent ? 'checked' : '' ?> style="width:auto">
      Custom accent color (overrides the theme's)
    </label>
    <p><input type="color" name="accent_color" value="<?= e($curAccent ?: '#19f0c7') ?>"
       style="width:64px;height:34px;padding:2px"></p>
    <p><button type="submit">Save Appearance</button></p>
  </form>
</div>

<div class="panel">
  <h3>Chat Settings</h3>
  <?php if ($role !== 'member'): ?>
    <p>Your name shows in
      <b style="color:<?= e(chat_color($role, '')) ?>"><?= e(role_label($role)) ?></b>
      staff color &mdash; it overrides any personal color.</p>
    <p class="muted"><b style="color:<?= e(chat_color($role, '')) ?>"><?= e($player['username']) ?>:</b> sample message</p>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="action" value="chatcolor">
      <label>Your chat name color</label>
      <p><input type="color" name="chat_color" value="<?= e($player['chat_color'] ?? '#c9d1e0') ?>"
         style="width:64px;height:34px;padding:2px"></p>
      <p class="muted"><b style="color:<?= e(chat_color('member', $player['chat_color'] ?? '')) ?>"><?= e($player['username']) ?>:</b> sample message</p>
      <p><button type="submit">Save Color</button></p>
    </form>
  <?php endif; ?>
</div>
