<?php /* pages/city.php — The Sprawl: district map hub */ ?>
<div class="panel">
  <h2>The Sprawl</h2>

  <div style="margin:0 -14px 8px">
  <svg viewBox="0 0 800 220" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg"
       style="width:100%;height:auto;max-height:240px;display:block;border-top:1px solid var(--line);border-bottom:1px solid var(--line)">
    <style>
      .bg{fill:var(--bg)}
      .panln{fill:var(--panel);stroke:var(--line);stroke-width:1.5}
      .arc{fill:var(--panel2);stroke:var(--accent);stroke-width:2}
      .accln{fill:none;stroke:var(--accent);stroke-width:1.5}
      .win{fill:var(--accent)}
      .win2{fill:var(--neon2)}
      .sign{fill:var(--accent);font:bold 24px Verdana,Arial,sans-serif;letter-spacing:7px}
      .sub{fill:var(--neon2);font:9px Verdana,Arial,sans-serif;letter-spacing:4px}
    </style>
    <defs>
      <filter id="sglow" x="-40%" y="-40%" width="180%" height="180%">
        <feGaussianBlur stdDeviation="2.5" result="b"/>
        <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
      </filter>
    </defs>
    <rect width="800" height="220" class="bg"/>
    <circle cx="120" cy="52" r="22" class="accln" opacity="0.55"/>
    <rect x="14" y="118" width="64" height="102" class="panln"/>
    <rect x="84" y="150" width="46" height="70" class="panln"/>
    <rect x="146" y="92" width="60" height="128" class="panln"/>
    <rect x="212" y="140" width="44" height="80" class="panln"/>
    <rect x="430" y="108" width="54" height="112" class="panln"/>
    <rect x="490" y="72" width="70" height="148" class="panln"/>
    <rect x="566" y="140" width="46" height="80" class="panln"/>
    <rect x="618" y="98" width="66" height="122" class="panln"/>
    <rect x="690" y="150" width="50" height="70" class="panln"/>
    <rect x="744" y="118" width="44" height="102" class="panln"/>
    <rect x="300" y="48" width="120" height="172" class="arc"/>
    <line x1="360" y1="48" x2="360" y2="26" class="accln"/>
    <circle cx="360" cy="24" r="3" class="win" filter="url(#sglow)"/>
    <line x1="78" y1="168" x2="146" y2="150" class="accln" opacity="0.4"/>
    <line x1="560" y1="118" x2="618" y2="128" class="accln" opacity="0.4"/>
    <g class="win" opacity="0.85">
      <rect x="24" y="130" width="6" height="8"/><rect x="40" y="130" width="6" height="8"/><rect x="56" y="146" width="6" height="8"/>
      <rect x="156" y="104" width="6" height="8"/><rect x="172" y="104" width="6" height="8"/><rect x="156" y="124" width="6" height="8"/><rect x="188" y="140" width="6" height="8"/>
      <rect x="500" y="86" width="6" height="8"/><rect x="518" y="86" width="6" height="8"/><rect x="536" y="104" width="6" height="8"/><rect x="500" y="120" width="6" height="8"/>
      <rect x="628" y="112" width="6" height="8"/><rect x="646" y="112" width="6" height="8"/><rect x="664" y="130" width="6" height="8"/>
      <rect x="752" y="130" width="6" height="8"/><rect x="768" y="146" width="6" height="8"/>
    </g>
    <g class="win2" opacity="0.8">
      <rect x="312" y="70" width="7" height="9"/><rect x="330" y="70" width="7" height="9"/><rect x="348" y="70" width="7" height="9"/>
      <rect x="384" y="88" width="7" height="9"/><rect x="402" y="88" width="7" height="9"/>
      <rect x="312" y="170" width="7" height="9"/><rect x="402" y="170" width="7" height="9"/>
    </g>
    <text x="360" y="132" text-anchor="middle" class="sign" filter="url(#sglow)">THE SPRAWL</text>
    <text x="360" y="152" text-anchor="middle" class="sub">SECTOR 9 // ARCOLOGY</text>
  </svg>
  </div>

  <p class="muted">Eleven districts, none of them safe. Pick a node and jack in.</p>
</div>
<div class="districts">

  <div class="district panel"><h4>The Grid Authority</h4><ul>
    <li><a href="index.php?p=cityhall">Admin Spire</a></li>
    <li><a href="index.php?p=registry">ID Registry</a></li>
  </ul></div>

  <div class="district panel"><h4>The Bazaar</h4><ul>
    <li><a href="index.php?p=bazaar">Open Market</a></li>
    <li><a href="index.php?p=ledger&act=bank">Bank</a></li>
    <li><a href="index.php?p=generalstore">The Supply Node</a></li>
  </ul></div>

  <div class="district panel"><h4>The Exchange Block</h4><ul>
    <li><a href="index.php?p=exchange">The Exchange</a></li>
  </ul></div>

  <div class="district panel"><h4>Neon Strip</h4><ul>
    <li><a href="index.php?p=lounge">The Static Lounge</a></li>
  </ul></div>

  <div class="district panel"><h4>The Undervolt</h4><ul>
    <li><a href="index.php?p=daemon">The Lucky Daemon</a></li>
  </ul></div>

  <div class="district panel"><h4>The Forge Quarter</h4><ul>
    <li><a href="index.php?p=blacksmith">The Blacksmith</a></li>
  </ul></div>

  <div class="district panel"><h4>The Firewall</h4><ul>
    <li><a href="index.php?p=sim">Combat Sim</a></li>
  </ul></div>

  <div class="district panel"><h4>The Loading Docks</h4><ul>
    <li><a href="index.php?p=transit">Transit Hub</a></li>
  </ul></div>

  <div class="district panel"><h4>The Datacore</h4><ul>
    <li><a href="index.php?p=datacore&act=lab">Skillsoft Lab</a></li>
    <li><a href="index.php?p=library">The Library</a></li>
  </ul></div>

  <div class="district panel"><h4>Foundry Sector</h4><ul>
    <li><a href="index.php?p=foundry">Fabrication Bay</a></li>
  </ul></div>

  <div class="district panel"><h4>The Hydrofarms</h4><ul>
    <li><a href="index.php?p=vats">Grow Vats</a></li>
  </ul></div>

  <div class="district panel"><h4>The Stacks</h4><ul>
    <li><a href="index.php?p=home">Your Hideout</a></li>
  </ul></div>

</div>
