
<!doctype html>
<html lang="en">

    <head>

        <meta charset="utf-8" />
        <title>@yield('pageTitle')</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta content="Premium Multipurpose Admin & Dashboard Template" name="description" />
        <meta content="Themesbrand" name="author" />
        <!-- App favicon -->
        <link rel="shortcut icon" href="/back/assets/images/favicon.ico">

        <!-- choices css -->
        <link href="/back/assets/libs/choices.js/public/assets/styles/choices.min.css" rel="stylesheet" type="text/css" />

        <link rel="stylesheet" href="/back/assets/libs/flatpickr/flatpickr.min.css">

        <link href="/back/assets/libs/sweetalert2/sweetalert2.min.css" rel="stylesheet" type="text/css" />
        <link rel="stylesheet" href="/back/assets/libs/glightbox/css/glightbox.min.css">

        <!-- preloader css -->
        <link rel="stylesheet" href="/back/assets/css/preloader.min.css" type="text/css" />

        <!-- DataTables -->
        <link href="/back/assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css" rel="stylesheet" type="text/css" />
        <link href="/back/assets/libs/datatables.net-buttons-bs4/css/buttons.bootstrap4.min.css" rel="stylesheet" type="text/css" />

        <!-- Responsive datatable examples -->
        <link href="/back/assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css" rel="stylesheet" type="text/css" />

        <!-- Bootstrap Css -->
        <link href="/back/assets/css/bootstrap.min.css" id="bootstrap-style" rel="stylesheet" type="text/css" />
        <!-- Icons Css -->
        <link href="/back/assets/css/icons.min.css" rel="stylesheet" type="text/css" />
        <link rel="stylesheet" href="/back/assets/libs/flatpickr/flatpickr.min.css">
        <!-- App Css-->
        <link href="/back/assets/css/app.min.css" id="app-style" rel="stylesheet" type="text/css" />
        <link rel="stylesheet" href="/extra-assets/summernote/summernote-bs4.min.css">
        @stack('custom-styles')

    </head>

    <body>

    <!-- <body data-layout="horizontal"> -->

        <!-- Begin page -->
        <div id="layout-wrapper">


            @include('back.layout.inc.header')

            
            @include('back.layout.inc.sidebar')
            <!-- Left Sidebar End -->



            <!-- ============================================================== -->
            <!-- Start right Content here -->
            <!-- ============================================================== -->
            <div class="main-content">

                <div class="page-content">
                    <div class="container-fluid">

                        <!-- Toaster start here -->
                        <div id="primary" class="toast hide fade align-items-center text-white bg-primary border-0" role="alert" aria-live="assertive" aria-atomic="true" style="position: fixed; right: 20px; top: 80px; z-index: 1050;">
                            <div class="d-flex">
                                <div class="toast-body">
                                    Hello, world! This is a toast message.
                                </div>
                                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                            </div>
                        </div>

                        <div id="success" class="toast hide fade align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true" style="position: fixed; right: 20px; top: 80px; z-index: 1050;">
                            <div class="d-flex">
                                <div class="toast-body">
                                    Hello, world! This is a toast message.
                                </div>
                                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                            </div>
                        </div>

                        <div id="warning" class="toast hide fade align-items-center text-white bg-warning border-0" role="alert" aria-live="assertive" aria-atomic="true" style="position: fixed; right: 20px; top: 80px; z-index: 1050;">
                            <div class="d-flex">
                                <div class="toast-body">
                                    Hello, world! This is a toast message.
                                </div>
                                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                            </div>
                        </div>

                        <div id="error" class="toast hide fade align-items-center text-white bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true" style="position: fixed; right: 20px; top: 80px; z-index: 1050;">
                            <div class="d-flex">
                                <div class="toast-body">
                                    Hello, world! This is a toast message.
                                </div>
                                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                            </div>
                        </div>

                        @yield('content')
                    </div>
                </div>
                <!-- End Page-content -->


                @include('back.layout.inc.footer')
            </div>
            <!-- end main content-->

        </div>
        <!-- END layout-wrapper -->

        <!-- JAVASCRIPT -->
        <script src="/back/assets/libs/jquery/jquery.min.js"></script>
        <script src="/back/assets/libs/bootstrap/js/bootstrap.bundle.min.js"></script>
        <script src="/back/assets/libs/metismenu/metisMenu.min.js"></script>
        <script src="/back/assets/libs/simplebar/simplebar.min.js"></script>
        <script src="/back/assets/libs/node-waves/waves.min.js"></script>
        <script src="/back/assets/libs/feather-icons/feather.min.js"></script>
        <!-- pace js -->
        <script src="/back/assets/libs/pace-js/pace.min.js"></script>

        <script src="/back/assets/libs/sweetalert2/sweetalert2.min.js"></script>
        <script src="/back/assets/js/pages/sweetalert.init.js"></script>

        <!-- glightbox js -->
        <script src="/back/assets/libs/glightbox/js/glightbox.min.js"></script>

        <!-- lightbox init -->
        <script src="/back/assets/js/pages/lightbox.init.js"></script>


        <!-- Required datatable js -->
        <script src="/back/assets/libs/datatables.net/js/jquery.dataTables.min.js"></script>
        <script src="/back/assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js"></script>
        <!-- Buttons examples -->
        <script src="/back/assets/libs/datatables.net-buttons/js/dataTables.buttons.min.js"></script>
        <script src="/back/assets/libs/datatables.net-buttons-bs4/js/buttons.bootstrap4.min.js"></script>
        <script src="/back/assets/libs/jszip/jszip.min.js"></script>
        <script src="/back/assets/libs/pdfmake/build/pdfmake.min.js"></script>
        <script src="/back/assets/libs/pdfmake/build/vfs_fonts.js"></script>
        <script src="/back/assets/libs/datatables.net-buttons/js/buttons.html5.min.js"></script>
        <script src="/back/assets/libs/datatables.net-buttons/js/buttons.print.min.js"></script>
        <script src="/back/assets/libs/datatables.net-buttons/js/buttons.colVis.min.js"></script>

        <!-- Responsive examples -->
        <script src="/back/assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js"></script>
        <script src="/back/assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js"></script>

        <!-- Datatable init js -->
        <script src="/back/assets/js/pages/datatables.init.js"></script>

        <link rel="stylesheet" href="/back/assets/libs/flatpickr/flatpickr.min.css">

        <!-- choices js -->
        <script src="/back/assets/libs/choices.js/public/assets/scripts/choices.min.js"></script>

        <script src="/back/assets/libs/flatpickr/flatpickr.min.js"></script>

        <script src="/back/assets/js/pages/form-advanced.init.js"></script>

        <script src="/back/assets/js/app.js"></script>
        <script src="/extra-assets/summernote/summernote-bs4.min.js"></script>

        @stack('custom-scripts')

        <script>

            $(document).ready(function() {
                $('.summernote').summernote({
                    height: 200
                });
            });

            
        </script>

        <script>
            // Function to show toasts
            // var successMessage = "{{ session('success') }}";
            // var errorMessage = "{{ session('error') }}";
            
            // // Function to show toasts
            // document.addEventListener('DOMContentLoaded', function() {
            //     // Show success toast if message exists
            //     if (successMessage) {
            //         document.getElementById('success').textContent = successMessage;
            //         var successToast = new bootstrap.Toast(document.getElementById('success-toast'));
            //         successToast.show();
            //     }
                
            //     if (errorMessage) {
            //         document.getElementById('error').textContent = errorMessage;
            //         var errorToast = new bootstrap.Toast(document.getElementById('error-toast'));
            //         errorToast.show();
            //     }
            // });
        </script>



    </body>
</html>
