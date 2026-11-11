<?php
/** @var string $content */
ob_start(); ?>
<div class="min-h-screen grid lg:grid-cols-2">
  <!-- Left: form -->
  <div class="flex flex-col px-6 py-8">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-2 font-semibold">
        <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand text-brand-fg"><?= icon('ticket', 18) ?></span>
        TicketingOS
      </div>
      <?= theme_toggle() ?>
    </div>
    <div class="flex flex-1 items-center justify-center">
      <div class="w-full max-w-sm animate-in"><?= $content ?></div>
    </div>
  </div>

  <!-- Right: brand panel -->
  <div class="relative hidden lg:block overflow-hidden bg-brand">
    <div class="absolute inset-0 bg-gradient-to-br from-indigo-500 via-indigo-600 to-violet-700"></div>
    <div class="absolute inset-0 opacity-20" style="background-image:radial-gradient(circle at 20% 30%, white 1px, transparent 1px);background-size:28px 28px"></div>
    <div class="relative z-10 flex h-full flex-col justify-end p-12 text-white">
      <h2 class="text-3xl font-bold leading-tight">Support that scales with every customer.</h2>
      <p class="mt-3 max-w-md text-white/80">
        A modern, multi-tenant helpdesk. Every organization gets its own
        isolated board, agents, and workflow — out of the box.
      </p>
    </div>
  </div>
</div>
<?php
echo render_partial('layouts/base', ['content' => ob_get_clean()]);
