<nav class="navbar sticky-top navbar-expand-lg navbar-light bg-light shadow-sm px-4 py-3">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold text-primary" href="index.php?page=dashboard">
            <i class="bi bi-graph-up me-2"></i>SENTIMENKNN
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
                <!-- Dropdown untuk Hasil Klasifikasi -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php echo (isset($page) && in_array($page, ['preprocessing', 'tfidf', 'knn'])) ? 'active' : ''; ?>" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        Hasil Klasifikasi
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="navbarDropdown">

                        <li>
                            <a class="dropdown-item <?php echo (isset($page) && $page == 'preprocessing') ? 'active' : ''; ?>" href="index.php?page=preprocessing">Preprocessing</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo (isset($page) && $page == 'tfidf') ? 'active' : ''; ?>" href="index.php?page=tfidf">TF-IDF</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo (isset($page) && $page == 'knn') ? 'active' : ''; ?>" href="index.php?page=knn">KNN</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo (isset($page) && $page == 'analisa') ? 'active' : ''; ?>" href="index.php?page=analisa">Analisa Kalimat</a>
                        </li>
                    </ul>
                </li>
            </ul>
            <!-- User Info & Logout Modal Trigger -->
            <div class="d-flex justify-content-center align-items-center fw-bold" data-bs-toggle="modal" data-bs-target="#userInfoModal" style="cursor: pointer;">
                <i class="bi bi-person-circle me-1 fs-3"></i>
                <?php echo $_SESSION['username']; ?>
            </div>
        </div>
    </div>
</nav>

<!-- Modal for Logout -->
<div class="modal fade" id="userInfoModal" tabindex="-1" aria-labelledby="userInfoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 shadow-lg">
            <div class="modal-header border-0">
                <h5 class="modal-title" id="userInfoModalLabel">Konfirmasi Logout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">Apakah Anda yakin ingin keluar dari web ini?</p>
            </div>
            <div class="modal-footer border-0">
                <a href="logout.php" class="btn btn-danger px-4 py-2 rounded-pill">Logout</a>
                <button type="button" class="btn btn-secondary px-4 py-2 rounded-pill" data-bs-dismiss="modal">Batal</button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>