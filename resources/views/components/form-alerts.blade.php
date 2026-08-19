<div>
    <div class="p-3">
        <div>
            @if (Session::get('info'))
                <div class="alert alert-info alert-dismissible fade show p-3" role="alert">
                    {!! Session::get('info') !!}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if (Session::get('fail'))
                <div class="alert alert-danger alert-dismissible fade show p-3" role="alert">
                    {!! Session::get('fail') !!}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if (Session::get('success'))
                <div class="alert alert-success alert-dismissible fade show p-3" role="alert">
                    {!! Session::get('success') !!}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

        </div>

    </div>
</div>