<!doctype html>
<html lang="id">
  <head>
    @yield('head')
  </head>
  <body @yield('body-attributes')>
    @yield('content')
    @stack('scripts')
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
  </body>
</html>
