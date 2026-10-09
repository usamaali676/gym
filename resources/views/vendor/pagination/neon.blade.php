@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="mt-8 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted">Showing <span class="num text-ink">{{ $paginator->firstItem() }}</span> to <span class="num text-ink">{{ $paginator->lastItem() }}</span> of <span class="num text-ink">{{ $paginator->total() }}</span></p>
        <ul class="flex flex-wrap items-center gap-1.5">
            @if ($paginator->onFirstPage())
                <li><span class="btn btn-ghost btn-sm" aria-disabled="true"><x-icon name="chevron-left" class="size-4"/><span class="sr-only">Previous</span></span></li>
            @else
                <li><a class="btn btn-ghost btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev"><x-icon name="chevron-left" class="size-4"/><span class="sr-only">Previous</span></a></li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="px-1 text-muted">{{ $element }}</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li>
                            @if ($page == $paginator->currentPage())
                                <span class="btn btn-primary btn-sm !min-w-9" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="btn btn-ghost btn-sm !min-w-9" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li><a class="btn btn-ghost btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next"><x-icon name="chevron-right" class="size-4"/><span class="sr-only">Next</span></a></li>
            @else
                <li><span class="btn btn-ghost btn-sm" aria-disabled="true"><x-icon name="chevron-right" class="size-4"/><span class="sr-only">Next</span></span></li>
            @endif
        </ul>
    </nav>
@endif
