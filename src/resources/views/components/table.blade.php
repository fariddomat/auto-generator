@props([
    'columns' => [],
    'data' => [],
    'routePrefix' => '',
    'parentId' => null, // New prop for parent resource ID (e.g., project ID)
    'show' => false,
    'edit' => false,
    'delete' => false,
    'restore' => false,
])

<div class="overflow-hidden bg-white shadow-md rounded-lg">
    <table class="w-full border-collapse">
        <!-- Table Head -->
        <thead class="bg-blue-800 text-white">
            <tr>
                <th class="px-4 py-2 border border-gray-300 text-left md:hidden">@lang('site.details')</th>
                @foreach ($columns as $col)
                    <th class="px-4 py-2 border border-gray-300 text-left hidden md:table-cell">
                        @lang('site.' . $col)
                    </th>
                @endforeach
                @if ($show || $edit || $delete || $restore)
                    <th class="px-4 py-2 border border-gray-300 text-center hidden md:table-cell">@lang('site.action')</th>
                @endif
            </tr>
        </thead>

        <!-- Table Body -->
        <tbody class="divide-y divide-gray-300 bg-gray-50">
            @forelse ($data as $row)
                <tr class="hover:bg-gray-100 transition" x-data="{ expanded: false }">
                    <!-- Mobile Expand Button -->
                    <td class="px-4 py-2 border border-gray-300 md:hidden" @click="expanded = !expanded">
                        <div class="flex justify-between items-center cursor-pointer">
                            <span>{{ $row->{$columns[0]} ?? '—' }}</span>
                            <button class="text-blue-500">
                                <i class="fas fa-chevron-down" x-show="!expanded"></i>
                                <i class="fas fa-chevron-up" x-show="expanded"></i>
                            </button>
                        </div>
                        <div class="flex-row p-4 bg-gray-100 border border-gray-300 mt-3" x-show="expanded">
                            @foreach ($columns as $col)
                                <div class="flex justify-between py-1">
                                    <strong>{{ ucfirst(str_replace('_', ' ', $col)) }}:</strong>
                                    <span>
                                        @if (($col === 'img' || $col === 'image') && $row->$col)
                                            <img src="{{ Storage::url($row->$col) }}" alt="Image"
                                                class="w-16 h-16 rounded">
                                        @elseif ($col === 'file' && $row->$col)
                                            <a href="{{ Storage::url($row->$col) }}" target="_blank">View PDF</a>
                                        @else
                                            {{ $row->$col ?? '—' }}
                                        @endif
                                    </span>
                                </div>
                            @endforeach

                            <!-- Actions in Mobile View -->
                            @if ($show || $edit || $delete || $restore)
                                <div class="mt-2 flex space-x-4">
                                    @if ($show)
                                        <a href="{{ route($routePrefix . '.show', $parentId ? [$parentId, $row->id] : $row->id) }}"
                                            class="text-blue-500 hover:text-blue-700" wire:navigate>
                                            <i class="fas fa-eye"></i> @lang('site.show')
                                        </a>
                                    @endif
                                    @if ($edit && !$row->trashed())
                                        <a href="{{ route($routePrefix . '.edit', $parentId ? [$parentId, $row->id] : $row->id) }}"
                                            class="text-yellow-500 hover:text-yellow-700" wire:navigate>
                                            <i class="fas fa-edit"></i> @lang('site.edit')
                                        </a>
                                    @endif
                                    @if ($delete && !$row->trashed())
                                        <form
                                            action="{{ route($routePrefix . '.destroy', $parentId ? [$parentId, $row->id] : $row->id) }}"
                                            method="POST" onsubmit="return confirm('@lang('site.are_you_sure')');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-500 hover:text-red-700">
                                                <i class="fas fa-trash"></i> @lang('site.delete')
                                            </button>
                                        </form>
                                    @endif
                                    @if ($restore && $row->trashed())
                                        <form
                                            action="{{ route($routePrefix . '.restore', $parentId ? [$parentId, $row->id] : $row->id) }}"
                                            method="POST" onsubmit="return confirm('@lang('site.are_you_sure_restore')');">
                                            @csrf
                                            <button type="submit" class="text-green-500 hover:text-green-700">
                                                <i class="fas fa-undo"></i> @lang('site.restore')
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </td>

                    <!-- Normal Columns (Hidden on Mobile) -->
                    @foreach ($columns as $col)
                        <td
                            class="px-4 py-2 border border-gray-300 hidden md:table-cell {{ $row->trashed() ? 'text-gray-400 italic' : '' }}">
                            @if (($col === 'img' || $col === 'image') && $row->$col)
                                <img src="{{ Storage::url($row->$col) }}" alt="Image" class="w-16 h-16 rounded">
                            @elseif ($col === 'file' && $row->$col)
                                <a href="{{ Storage::url($row->$col) }}" target="_blank">View PDF</a>
                            @else
                                {{ $row->$col ?? '—' }}
                            @endif
                        </td>
                    @endforeach

                    <!-- Actions (Hidden on Mobile) -->
                    @if ($show || $edit || $delete || $restore)
                        <td class="px-4 py-2 border border-gray-300 text-center hidden md:table-cell">
                            <div class="flex justify-center space-x-1">
                                @if ($show)
                                    <a href="{{ route($routePrefix . '.show', $parentId ? [$parentId, $row->id] : $row->id) }}"
                                        class="text-blue-500 hover:text-blue-700" wire:navigate>
                                        <i class="fas fa-eye"></i>
                                    </a>
                                @endif
                                @if ($edit && !$row->trashed())
                                    <a href="{{ route($routePrefix . '.edit', $parentId ? [$parentId, $row->id] : $row->id) }}"
                                        class="text-yellow-500 hover:text-yellow-700" wire:navigate>
                                        <i class="fas fa-edit"></i>
                                    </a>
                                @endif
                                @if ($delete && !$row->trashed())
                                    <form
                                        action="{{ route($routePrefix . '.destroy', $parentId ? [$parentId, $row->id] : $row->id) }}"
                                        method="POST" onsubmit="return confirm('@lang('site.are_you_sure')');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-500 hover:text-red-700">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                @endif
                                @if ($restore && $row->trashed())
                                    <form
                                        action="{{ route($routePrefix . '.restore', $parentId ? [$parentId, $row->id] : $row->id) }}"
                                        method="POST" onsubmit="return confirm('@lang('site.are_you_sure_restore')');">
                                        @csrf
                                        <button type="submit" class="text-green-500 hover:text-green-700">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) + 1 }}" class="px-4 py-2 text-center text-gray-500">
                        @lang('site.no_records_found')
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
