<div class="hidden md:flex md:flex-shrink-0">
    <div class="flex flex-col w-64">
        <div class="flex items-center h-16 flex-shrink-0 px-4 bg-white border-r justify-center z-10">
            <a href="{{ route('client.dashboard') }}">
                <img class="h-10 w-auto sidebar_logo_override" src="{!! auth()->guard('contact')->user()->company->present()->logo($settings) !!}"
                     alt="{{ auth()->guard('contact')->user()->company->present()->name() }} logo"/>
            </a>
        </div>
        <div class="h-0 flex-1 flex flex-col overflow-y-auto z-0 border-r">
            <nav class="flex-1 pb-4 pt-0 bg-white">
                @foreach($sidebar as $row)
                    <a class="group flex items-center p-4 text-sm leading-5 font-medium hover:font-semibold focus:outline-none focus:bg-primary-darken transition ease-in-out duration-150 {{ isActive($row['url'], true) ? 'bg-primary text-white' : 'text-gray-900' }}"
                       href="{{ route($row['url']) }}"
                       id="{{ $row['id'] }}">
                        @if(isActive($row['url'], true))
                            <img src="{{ asset('images/svg/' . $row['icon'] . '.svg') }}"
                                 class="w-5 h-5 fill-current text-white mr-3" alt=""/>
                        @else
                            <img src="{{ asset('images/svg/dark/' . $row['icon'] . '.svg') }}"
                                 class="w-5 h-5 fill-current text-white mr-3" alt=""/>
                        @endif

                        <span>{{ $row['title'] }}</span>
                    </a>
                @endforeach
            </nav>

	            
        </div>
        <div class="flex-shrink-0 w-14"></div>
    </div>
</div>
