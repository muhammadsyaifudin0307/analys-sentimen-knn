<nav class="navbar navbar-expand-lg navbar-light bg-light shadow-sm px-4 py-3">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold text-primary" href="index.php?page=dashboard">
            <i class="bi bi-graph-up me-2"></i>SENTIMENSVM
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 fw-bolder">
                <li class="nav-item">
                    <a class="nav-link <?php echo (isset($page) && $page == 'dashboard') ? 'active' : ''; ?>" href="index.php?page=dashboard">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo (isset($page) && $page == 'datalatih') ? 'active' : ''; ?>" href="index.php?page=datalatih">Data Latih</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo (isset($page) && $page == 'datauji') ? 'active' : ''; ?>" href="index.php?page=datauji">Data Uji</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo (isset($page) && $page == 'analisasvm') ? 'active' : ''; ?>" href="index.php?page=analisasvm">Hasil Klasifikasi</a>
                </li>
            </ul>
            <div class="d-flex justify-content-center align-items-center fw-bold" data-bs-toggle="modal" data-bs-target="#userInfoModal" style="cursor: pointer;">
                <i class="bi bi-person-circle me-1 fs-3"></i>
                <?php echo $_SESSION['username']; ?>
            </div>
        </div>
    </div>
</nav>


<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>