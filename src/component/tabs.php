<div class="nav nav-tabs" id="analysisTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="preprocessing-tab" data-bs-toggle="tab" data-bs-target="#preprocessing" type="button" role="tab">Preprocessing</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tfidf-tab" data-bs-toggle="tab" data-bs-target="#tfidf" type="button" role="tab">TF-IDF</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="knn-tab" data-bs-toggle="tab" data-bs-target="#knn" type="button" role="tab">Analisis KNN</button>
    </li>
</div>

<div class="tab-content" id="analysisTabsContent">
    <!-- Preprocessing Tab -->
    <div class="tab-pane fade show active" id="preprocessing" role="tabpanel">
        <?php include($_SERVER['DOCUMENT_ROOT'] . '/SentimenSvm/src/pages/preprocessing.php'); ?> <!-- Include konten preprocessing -->
    </div>

    <!-- TF-IDF Tab -->
    <div class="tab-pane fade" id="tfidf" role="tabpanel">
        <?php include($_SERVER['DOCUMENT_ROOT'] . '/SentimenSvm/src/pages/tfidf.php'); ?> <!-- Include konten tfidf -->
    </div>

    <!-- KNN Analysis Tab -->
    <div class="tab-pane fade" id="knn" role="tabpanel">
        <?php include($_SERVER['DOCUMENT_ROOT'] . '/SentimenSvm/src/pages/knn.php'); ?> <!-- Include konten knn -->
    </div>
</div>