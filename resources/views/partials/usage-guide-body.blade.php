@php($g = $guide)

<div class="usage-guide w-full max-w-none space-y-8">
    <header class="space-y-4 border-b border-gray-200 pb-6 dark:border-white/10">
        <p class="text-sm text-gray-500 dark:text-gray-400">İki konu: faaliyet raporu ve SWOT. Aşağıdan bölüme atlayın veya yan yana kartları kullanın.</p>
        <nav class="flex flex-wrap gap-2" aria-label="Rehber bölümleri">
            @foreach ($g['sections'] as $i => $s)
                <a
                    href="#rehber-{{ $s['key'] }}"
                    class="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-800 shadow-sm transition hover:border-gray-300 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100 dark:hover:border-white/20 dark:hover:bg-white/5"
                >
                    <span class="flex h-6 w-6 items-center justify-center rounded-md {{ $s['ui']['icon_soft'] }} text-xs font-bold tabular-nums">{{ $i + 1 }}</span>
                    {{ $s['title'] }}
                </a>
            @endforeach
        </nav>
    </header>

    <section aria-labelledby="usage-guide-intro-heading" class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900 sm:p-7">
        <h2 id="usage-guide-intro-heading" class="sr-only">Giriş</h2>
        <div class="border-l-4 border-l-amber-500 pl-5">
            <p class="text-base font-semibold text-gray-900 dark:text-white">{{ $g['intro']['title'] }}</p>
            <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300">{{ $g['intro']['text'] }}</p>
            <p class="mt-4 flex gap-2 rounded-lg bg-gray-50 px-3 py-2.5 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-200">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-gray-500 dark:text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 12.75 3 3m0 0 3-3m-3 3v-7.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <span>Sayfanın sağ üstündeki <strong class="font-semibold text-gray-900 dark:text-white">PDF indir</strong> ile bu metni dosya olarak kaydedebilirsiniz.</span>
            </p>
        </div>
    </section>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2 xl:items-start xl:gap-8">
        @foreach ($g['sections'] as $index => $section)
            <article
                id="rehber-{{ $section['key'] }}"
                class="scroll-mt-6 flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900"
            >
                <div class="flex min-h-0 flex-1 flex-col border-l-4 {{ $section['ui']['border_left'] }}">
                    <header class="border-b border-gray-100 px-5 py-5 dark:border-white/10 sm:px-6 sm:py-6">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg {{ $section['ui']['icon_bg'] }} text-white shadow-sm">
                                @if (($section['key'] ?? '') === 'swot')
                                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" />
                                    </svg>
                                @else
                                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v7.125C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                                    </svg>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Bölüm {{ $index + 1 }}</p>
                                <div class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $section['title'] }}</h3>
                                    <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">{{ $section['badge'] }}</span>
                                </div>
                                <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ $section['lead'] }}</p>
                            </div>
                        </div>
                    </header>

                    <div class="flex flex-1 flex-col space-y-6 px-5 py-6 sm:px-6 sm:py-7">
                        <section>
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Nereden başlarım?</h4>
                            <p class="mt-2 rounded-lg border border-gray-100 bg-gray-50/90 p-4 text-sm leading-relaxed text-gray-800 dark:border-white/5 dark:bg-white/[0.03] dark:text-gray-200">
                                {{ $section['menu'] }}
                            </p>
                        </section>

                        <section class="flex-1">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Adım adım</h4>
                            <ol class="mt-3 space-y-3">
                                @foreach ($section['steps'] as $i => $step)
                                    <li class="flex gap-3 sm:gap-4">
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-xs font-bold tabular-nums {{ $section['ui']['step_badge'] }}">{{ $i + 1 }}</span>
                                        <div class="min-w-0 flex-1 rounded-lg border border-gray-100 bg-white p-3.5 text-sm leading-relaxed text-gray-800 dark:border-white/5 dark:bg-gray-950/30 dark:text-gray-200">
                                            {{ $step }}
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        </section>

                        @if (($section['notes'] ?? []) !== [])
                            <aside class="mt-auto rounded-lg border border-sky-200/70 bg-sky-50/60 p-4 dark:border-sky-900/40 dark:bg-sky-950/25">
                                <p class="flex items-center gap-2 text-sm font-semibold text-sky-900 dark:text-sky-200">
                                    <svg class="h-5 w-5 shrink-0 text-sky-600 dark:text-sky-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75.958v-.256m0 0a12.06 12.06 0 0 0-4.5 0m0 0v.256m0-6.75h.008v.008h-.008v-.008Z" />
                                    </svg>
                                    İpuçları
                                </p>
                                <ul class="mt-2 space-y-2 text-sm leading-relaxed text-sky-950/90 dark:text-sky-100/85">
                                    @foreach ($section['notes'] as $note)
                                        <li class="flex gap-2 pl-0.5">
                                            <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-sky-500 dark:bg-sky-400" aria-hidden="true"></span>
                                            <span>{{ $note }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </aside>
                        @endif
                    </div>
                </div>
            </article>
        @endforeach
    </div>
</div>
