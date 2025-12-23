<?php
/**
 * tmpl/default.php
 */

declare(strict_types=1);

defined('_JEXEC') or die;

$gameKey = htmlspecialchars((string) $params->get('game_key', 'game'), ENT_QUOTES, 'UTF-8');
$headline = (string) $params->get('headline', '');
$subtitle = (string) $params->get('subtitle', '');
$skaiUrl  = (string) $params->get('skai_url', '/');
$resultsUrl = (string) $params->get('results_url', '/');
$membershipUrl = (string) $params->get('membership_url', '/');

$defaultMethod = (string) $params->get('default_method', 'random');
$defaultLines  = (int) $params->get('default_lines', 5);

$extraEnabled = (int) $params->get('extra_enabled', 1) === 1;
$extraLabel   = htmlspecialchars((string) $params->get('extra_label', 'X'), ENT_QUOTES, 'UTF-8');

$moduleId = (int) $module->id;

// Provide initial picks from PHP
$initialJson = json_encode($initial, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<div class="le-ii-mod" data-game="<?php echo $gameKey; ?>" data-module-id="<?php echo (int)$moduleId; ?>">
  <div class="le-hero">
    <div class="le-hero-top">
      <div>
        <p class="le-kicker">
          Instant Intent • Quick Picks
          <span class="le-dot" aria-hidden="true"></span>
          LottoExpert.net
        </p>
        <h2 class="le-title"><?php echo htmlspecialchars($headline, ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="le-subtitle"><?php echo $subtitle; ?></p>

        <div class="le-hero-actions">
          <a class="le-btn le-btn-primary" href="<?php echo htmlspecialchars($skaiUrl, ENT_QUOTES, 'UTF-8'); ?>">
            Open SKAI Analysis
          </a>
          <a class="le-btn" href="<?php echo htmlspecialchars($resultsUrl, ENT_QUOTES, 'UTF-8'); ?>">
            Latest Results
          </a>
          <a class="le-btn" href="<?php echo htmlspecialchars($membershipUrl, ENT_QUOTES, 'UTF-8'); ?>">
            Membership Options
          </a>
        </div>

        <p class="le-mini">
          Method labels are transparent: Random uses a secure RNG; Hot/Cold and Skip–Hit use recent draw history heuristics.
        </p>
      </div>

      <div class="le-badge" role="note" aria-label="Responsible play note">
        No guarantees • Data-first • Play responsibly
      </div>
    </div>

    <div class="le-grid">
      <div class="le-card">
        <div class="le-card-h">
          <h3 class="le-card-title">Generate Picks</h3>

          <div class="le-controls">
            <label class="le-field">
              Method
              <select class="le-method" aria-label="Pick method">
                <option value="random" <?php echo $defaultMethod === 'random' ? 'selected' : ''; ?>>Random</option>
                <option value="hotcold" <?php echo $defaultMethod === 'hotcold' ? 'selected' : ''; ?>>Hot/Cold</option>
                <option value="skiphit" <?php echo $defaultMethod === 'skiphit' ? 'selected' : ''; ?>>Skip–Hit</option>
              </select>
            </label>

            <label class="le-field">
              Lines
              <input class="le-lines" type="number" value="<?php echo (int)$defaultLines; ?>" min="1" max="50" />
            </label>

            <button type="button" class="le-icon-btn le-generate">Generate</button>
          </div>
        </div>

        <div class="le-card-body">
          <div class="le-note">
            <strong>Next step:</strong> Use SKAI to validate context (skips, frequency, and trends) before you save or play.
          </div>

          <div class="le-divider" aria-hidden="true"></div>

          <div class="le-picks" aria-live="polite"></div>

          <div class="le-foot">
            <a class="le-link" href="<?php echo htmlspecialchars($skaiUrl, ENT_QUOTES, 'UTF-8'); ?>">Open SKAI</a>
            <span class="le-dot" aria-hidden="true"></span>
            <span>Tip: compare Random vs Skip–Hit lines to see overlap.</span>
          </div>
        </div>
      </div>

      <div class="le-card">
        <div class="le-card-h">
          <h3 class="le-card-title">How methods work</h3>
        </div>
        <div class="le-card-body le-method-card">
          <div class="le-method-pill">
            <h4>Random</h4>
            <p>Secure random selection within the game range.</p>
          </div>
          <div class="le-method-pill">
            <h4>Hot/Cold</h4>
            <p>Builds a pool from the most frequent and least frequent recent numbers, then samples unique lines.</p>
          </div>
          <div class="le-method-pill">
            <h4>Skip–Hit</h4>
            <p>Uses recent history to estimate “hit after skip” behavior and ranks numbers using a transparent heuristic score.</p>
          </div>
          <?php if ($extraEnabled): ?>
          <div class="le-method-pill">
            <h4>Extra Ball (<?php echo $extraLabel; ?>)</h4>
            <p>Generated per line. You can extend to history-based extra later if you want.</p>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="le-toast" role="status" aria-live="polite"></div>

  <script>
    window.LEInstantIntent = window.LEInstantIntent || {};
    window.LEInstantIntent["<?php echo $gameKey; ?>"] = <?php echo $initialJson ?: '{}'; ?>;
  </script>
</div>
