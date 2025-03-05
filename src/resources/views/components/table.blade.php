@props([
    'columns' => [],
    'data' => [],
    'routePrefix' => '',
    'show' => false,
    'edit' => false,
    'delete' => false,
])

<div class="overflow-hidden bg-white shadow-md rounded-lg">

    <table class="w-full border-collapse">
        <!-- Table Head -->
        <thead class="bg-blue-800 text-white">
            <tr>
                {{-- <th class="px-4 py-2 border border-gray-300 text-left md:hidden">Toggel</th> --}}
                <th class="px-4 py-2 border border-gray-300 text-left md:hidden">@lang('site.details')</th>
                @foreach ($columns as $col)
                    <th class="px-4 py-2 border border-gray-300 text-left hidden md:table-cell">
                        @lang('site.'.$col)
                    </th>
                @endforeach
                @if ($show || $edit || $delete)
                    <th class="px-4 py-2 border border-gray-300 text-center hidden md:table-cell">@lang('site.action')</th>
                @endif
            </tr>
        </thead>

        <!-- Table Body -->
        <tbody class="divide-y divide-gray-300 bg-gray-50">
            @foreach ($data as $row)
                <tr class="hover:bg-gray-100 transition" x-data="{ expanded: false }">
                    <!-- Mobile Expand Button -->
                    <td class="px-4 py-2 border border-gray-300 md:hidden"
                        @click="expanded = !expanded">
                        <div class=" flex justify-between items-center cursor-pointer">
                        <span>{{ is_object($row) ? $row->{$columns[0]} : $row[$columns[0]] ?? '—' }}</span>
                        <button class="text-blue-500">
                            <i class="fas fa-chevron-down" x-show="!expanded"></i>
                            <i class="fas fa-chevron-up" x-show="expanded"></i>
                        </button>
                        </div>
                    <div colspan="{{ count($columns) + ($show || $edit || $delete ? 1 : 0) }}"
                        class="flex-row p-4 bg-gray-100 border border-gray-300 mt-3" x-show="expanded">
                        @foreach ($columns as $col)
                            <div class="flex justify-between py-1">
                                <strong>{{ ucfirst(str_replace('_', ' ', $col)) }}:</strong>
                                <span>{{ is_object($row) ? $row->$col : $row[$col] ?? '—' }}</span>
                            </div>
                        @endforeach

                        <!-- Actions in Mobile View -->
                        @if ($show || $edit || $delete)
                            <div class="mt-2 flex space-x-4">
                                @if ($show)
                                    <a href="{{ route($routePrefix . '.show', $row->id) }}"
                                        class="text-blue-500 hover:text-blue-700" wire:navigate>
                                        <i class="fas fa-eye"></i> Show
                                    </a>
                                @endif
                                @if ($edit)
                                    <a href="{{ route($routePrefix . '.edit', $row->id) }}"
                                        class="text-yellow-500 hover:text-yellow-700">
                                        <i class="fas fa-edit"></i> @lang('site.edit')
                                    </a>
                                @endif
                                @if ($delete)
                                    <form action="{{ route($routePrefix . '.destroy', $row->id) }}" method="POST"
                                        onsubmit="return confirm('Are you sure?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-500 hover:text-red-700">
                                            <i class="fas fa-trash"></i> @lang('site.delete')
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @endif
                        </div>
                    </td>

                    <div class="">
                        <!-- Normal Columns (Hidden on Mobile) -->
                        @foreach ($columns as $index => $col)
                            <td class="px-4 py-2 border border-gray-300  hidden md:table-cell">
                                {{ is_object($row) ? $row->$col : $row[$col] ?? '—' }}
                            </td>
                        @endforeach

                        <!-- Actions (Hidden on Mobile) -->
                        @if ($show || $edit || $delete)
                            <td class="px-4 py-2 border border-gray-300 text-center  hidden md:table-cell">
                                <div class="flex justify-center space-x-1">
                                    @if ($show)
                                        <a href="{{ route($routePrefix . '.show', $row->id) }}"
                                            class="text-blue-500 hover:text-blue-700" wire:navigate>
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    @endif
                                    @if ($edit)
                                        <a href="{{ route($routePrefix . '.edit', $row->id) }}"
                                            class="text-yellow-500 hover:text-yellow-700" wire:navigate>
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    @endif
                                    @if ($delete)
                                        <form action="{{ route($routePrefix . '.destroy', $row->id) }}" method="POST"
                                            onsubmit="return confirm('Are you sure?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-500 hover:text-red-700">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </div>

                </tr>
            @endforeach
        </tbody>
    </table>
</div>
