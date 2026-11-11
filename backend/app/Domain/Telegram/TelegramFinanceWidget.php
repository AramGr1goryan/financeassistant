<?php

namespace App\Domain\Telegram;

final class TelegramFinanceWidget
{
    public static function html(): string
    {
        return <<<'HTML'
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    margin: 0;
    padding: 12px;
    background: var(--tg-theme-bg-color, #ffffff);
    color: var(--tg-theme-text-color, #111827);
    font-size: 13px;
    line-height: 1.45;
  }
  .title {
    font-weight: 700;
    font-size: 14px;
    margin-bottom: 10px;
    color: var(--tg-theme-text-color, #111827);
  }
  .actions {
    display: flex;
    gap: 6px;
    margin-bottom: 10px;
    flex-wrap: wrap;
  }
  button {
    background: var(--tg-theme-button-color, #2563eb);
    color: var(--tg-theme-button-text-color, #ffffff);
    border: none;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.15s ease;
  }
  button:active {
    opacity: 0.8;
  }
  #output {
    white-space: pre-wrap;
    background: var(--tg-theme-secondary-bg-color, #f1f5f9);
    border-radius: 8px;
    padding: 10px;
    font-size: 12px;
    font-family: ui-monospace, monospace;
    margin: 0;
    min-height: 48px;
    box-sizing: border-box;
  }
</style>
</head>
<body>
  <div class="title">Finance Assistant</div>
  <div class="actions">
    <button id="btn-balance">Balance</button>
    <button id="btn-budget">Budget</button>
    <button id="btn-accounts">Accounts</button>
  </div>
  <pre id="output">Tap a button above to load authoritative financial data.</pre>
  <script>
    function bind(btnId, action) {
      var btn = document.getElementById(btnId);
      if (!btn) return;
      btn.addEventListener('click', function () {
        var out = document.getElementById('output');
        out.textContent = 'Loading...';
        if (typeof TelegramWidget !== 'undefined' && TelegramWidget.request) {
          TelegramWidget.request(action).then(function (answer) {
            out.textContent = (answer && answer.result) ? answer.result : 'No data returned.';
          }, function () {
            out.textContent = 'Could not load data. Please try again.';
          });
        } else {
          out.textContent = 'TelegramWidget API is unavailable in this client.';
        }
      });
    }
    bind('btn-balance', 'balance');
    bind('btn-budget', 'budget');
    bind('btn-accounts', 'accounts');
  </script>
</body>
</html>
HTML;
    }
}
