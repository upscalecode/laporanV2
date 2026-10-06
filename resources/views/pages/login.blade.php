@extends('layouts.app')

@section('head')
    @include('partials.head-login')
@endsection

@section('body-attributes')data-page="login"@endsection

@section('content')
<main class="login-screen">
      <section class="login-card" aria-labelledby="loginTitle">
        <div class="login-mark">
          <img
            src="{{ asset('assets/images/logogram.png') }}"
            alt="Logo ABSH"
            class="brand-logo"
          />
          <div>
            <p class="eyebrow">PT. ABSH FRAGRANCE CREATIONS</p>
            <h1 id="loginTitle">LAPORAN PRODUKSI</h1>
          </div>
        </div>

        <p class="login-sub">Login Produksi Filling &amp; Press.</p>

        <form id="loginForm" class="login-form" autocomplete="off">
          <label class="field">
            <span>Username</span>
            <input
              type="text"
              id="loginUsername"
              required
              autocomplete="username"
              placeholder="Username"
            />
          </label>

          <label class="field">
            <span>Password</span>
            <input
              type="password"
              id="loginPassword"
              required
              autocomplete="current-password"
              placeholder="••••••••"
            />
            <!-- <button type="button" id="togglePassword" class="togglePassword">👁️</button> -->
          </label>

          <p id="loginError" class="form-error" hidden></p>
          <button
            id="loginSubmit"
            type="submit"
            class="btn btn-primary btn-block"
          >
            Login
          </button>
        </form>

        <div class="login-hint">
          <p><strong>Status koneksi</strong></p>
          <p id="loginConnectionText">Siap untuk login</p>
        </div>
      </section>
    </main>
@endsection
