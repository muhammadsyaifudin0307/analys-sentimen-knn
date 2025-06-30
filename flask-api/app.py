from flask import Flask, request, jsonify, Blueprint
import re
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory
from Sastrawi.StopWordRemover.StopWordRemoverFactory import StopWordRemoverFactory
from collections import Counter
from math import log10, sqrt
import numpy as np

# Inisialisasi Flask app
app = Flask(__name__)

# Inisialisasi Stopword Remover dan Stemmer dari Sastrawi
factory_stopword = StopWordRemoverFactory()
stopword_remover = factory_stopword.create_stop_word_remover()

factory_stemmer = StemmerFactory()
stemmer = factory_stemmer.create_stemmer()

def load_slang_dictionary(filename='slang_dictionary.txt'):
    slang_dict = {}
    try:
        with open(filename, 'r', encoding='utf-8') as file:
            for line in file:
                line = line.strip()
                if ' => ' not in line or not line:
                    continue
                parts = line.split(' => ')
                if len(parts) != 2:
                    continue
                slang, standard = parts
                slang_dict[slang.strip()] = standard.strip()
    except FileNotFoundError:
        print("Error: Slang dictionary file not found.")
    return slang_dict

# Load slang dictionary
slang_dict = load_slang_dictionary()

# Fungsi untuk membersihkan teks
def clean_text(text):
    text = re.sub(r'http\S+', '', text)
    text = re.sub(r'@\S+', '', text)
    text = re.sub(r'#\S+', '', text)
    text = re.sub(r'[^a-zA-Z\s]', '', text)
    text = re.sub(r'\s+', ' ', text).strip()
    return text

# Fungsi untuk menggantikan slang dengan kata baku
def normalize_slang(text):
    words = text.split()
    normalized_words = [slang_dict.get(word, word) for word in words]
    return ' '.join(normalized_words)

# Fungsi untuk normalisasi teks sederhana
def normalize_text(text):
    return text.lower()

# Fungsi untuk preprocessing teks (tahapan bertahap)
def preprocess_text_step_by_step(text):
    cleaned_text = clean_text(text)
    casefolded_text = cleaned_text.lower()
    normalized_text = normalize_text(casefolded_text)
    slang_normalized_text = normalize_slang(normalized_text)
    tokenized_text = slang_normalized_text.split()
    stopword_removed_text = stopword_remover.remove(' '.join(tokenized_text))
    stemmed_text = [stemmer.stem(word) for word in stopword_removed_text.split()]
    return {
        'cleaned_text': cleaned_text,
        'casefolded_text': casefolded_text,
        'normalized_text': normalized_text,
        'slang_normalized_text': slang_normalized_text,
        'tokenized_text': tokenized_text,
        'stopword_removed_text': stopword_removed_text,
        'stemmed_text': " ".join(stemmed_text)
    }

# Fungsi untuk menghitung Term Frequency (TF)
def compute_tf(text):
    word_count = Counter(text.split())
    total_words = len(text.split())
    if total_words == 0:
        return {}, {}
    tf = {word: count / total_words for word, count in word_count.items()}
    return tf, word_count

# Fungsi untuk menghitung Document Frequency (DF) dan IDF
def compute_idf(texts):
    N = len(texts)
    if N == 0:
        return {}
    
    df_dict = {}
    for text in texts:
        words_in_text = set(text.split())
        for word in words_in_text:
            if word in df_dict:
                df_dict[word] += 1
            else:
                df_dict[word] = 1
    
    idf_dict = {word: log10(N / df) for word, df in df_dict.items()}
    return idf_dict

# Fungsi untuk menghitung TF-IDF secara manual
def compute_tfidf_manual(texts):
    all_terms = set()
    for text in texts:
        all_terms.update(text.split())
    all_terms = sorted(list(all_terms))
    
    tf_results = []
    word_counts = []
    for text in texts:
        tf, word_count = compute_tf(text)
        tf_results.append(tf)
        word_counts.append(word_count)
    
    idf_dict = compute_idf(texts)
    
    tfidf_results = []
    for i, text in enumerate(texts):
        tfidf_doc = {}
        tf_doc = tf_results[i]
        for term in all_terms:
            tf_value = tf_doc.get(term, 0)
            idf_value = idf_dict.get(term, 0)
            tfidf_value = tf_value * idf_value
            tfidf_doc[term] = tfidf_value
        tfidf_results.append({
            'text': text,
            'word_count': word_counts[i],
            'tf': tf_doc,
            'tfidf': tfidf_doc,
            'terms': all_terms
        })
    return tfidf_results, idf_dict

# Fungsi untuk mengkonversi tfidf dictionary ke vector
def tfidf_to_vector(tfidf_doc, all_terms):
    """Mengkonversi dictionary TF-IDF ke vector berdasarkan urutan terms"""
    vector = []
    for term in all_terms:
        vector.append(tfidf_doc.get(term, 0))
    return np.array(vector)

# Fungsi untuk menghitung Euclidean Distance
def euclidean_distance(vector1, vector2):
    """
    Menghitung Euclidean Distance
    Formula: √(Σ(xi - yi)²)
    """
    if len(vector1) != len(vector2):
        return float('inf')
    
    # Convert to numpy arrays if needed
    v1 = np.array(vector1) if not isinstance(vector1, np.ndarray) else vector1
    v2 = np.array(vector2) if not isinstance(vector2, np.ndarray) else vector2
    
    # Hitung perbedaan setiap dimensi (xi - yi)
    differences = v1 - v2
    
    # Hitung kuadrat perbedaan (xi - yi)²
    squared_differences = differences ** 2
    
    # Sum of squared differences Σ(xi - yi)²
    sum_squared_diff = np.sum(squared_differences)
    
    # Euclidean distance √(Σ(xi - yi)²)
    distance = sqrt(sum_squared_diff)
    
    return float(distance)


# --- Bagian compute_confusion_matrix ---
def compute_confusion_matrix(y_true, y_pred):
    """
    Menghitung confusion matrix dan macro average metrics (precision, recall, f1-score, accuracy)
    """
    if len(y_true) != len(y_pred):
        return {"error": "Length of y_true and y_pred must be equal"}

    if len(y_true) == 0:
        return {"error": "No data provided"}

    total_samples = len(y_true)
    correct_predictions = sum(1 for t, p in zip(y_true, y_pred) if t == p)
    incorrect_predictions = total_samples - correct_predictions
    accuracy = correct_predictions / total_samples if total_samples > 0 else 0

    # Ambil semua label unik
    labels = sorted(list(set(y_true + y_pred)))
    detailed_confusion_matrix = {label: {l: 0 for l in labels} for label in labels}

    # Hitung jumlah untuk setiap kombinasi aktual-prediksi
    for true_label, pred_label in zip(y_true, y_pred):
        detailed_confusion_matrix[true_label][pred_label] += 1

    # Hitung metrik per label
    label_metrics = {}
    precisions, recalls, f1_scores, supports = [], [], [], []

    for label in labels:
        tp = detailed_confusion_matrix[label][label]
        fp = sum(detailed_confusion_matrix[other][label] for other in labels if other != label)
        fn = sum(detailed_confusion_matrix[label][other] for other in labels if other != label)
        tn = total_samples - tp - fp - fn
        support = sum(detailed_confusion_matrix[label].values())

        precision = tp / (tp + fp) if (tp + fp) > 0 else 0
        recall = tp / (tp + fn) if (tp + fn) > 0 else 0
        f1 = 2 * precision * recall / (precision + recall) if (precision + recall) > 0 else 0

        label_metrics[label] = {
            'tp': tp, 'fp': fp, 'fn': fn, 'tn': tn,
            'support': support,
            'precision': round(precision, 4),
            'recall': round(recall, 4),
            'f1_score': round(f1, 4)
        }

        precisions.append(precision)
        recalls.append(recall)
        f1_scores.append(f1)
        supports.append(support)

    # Macro average
    macro_precision = sum(precisions) / len(labels) if labels else 0
    macro_recall = sum(recalls) / len(labels) if labels else 0
    macro_f1 = sum(f1_scores) / len(labels) if labels else 0

    # Debug print
    print("\n===== Confusion Matrix Debug =====")
    print("Labels       :", labels)
    print("Actual       :", y_true)
    print("Predicted    :", y_pred)
    print("Confusion Matrix (detailed):")
    for true_label in labels:
        row = [detailed_confusion_matrix[true_label][pred_label] for pred_label in labels]
        print(f"{true_label:10}: {row}")
    print("Per-Label Metrics:")
    for label, metrics in label_metrics.items():
        print(f"{label:10}: {metrics}")
    print("Macro Avg Metrics:")
    print({
        'precision': round(macro_precision, 4),
        'recall': round(macro_recall, 4),
        'f1_score': round(macro_f1, 4),
        'accuracy': round(accuracy, 4)
    })
    print("==================================\n")

    # Struktur hasil evaluasi
    results = {
        'confusion_matrix_summary': {
            'correct_predictions': correct_predictions,
            'incorrect_predictions': incorrect_predictions,
            'total_samples': total_samples
        },
        'detailed_confusion_matrix': detailed_confusion_matrix,
        'per_label_metrics': label_metrics,
        'per_label_lists': {
            'precision': [round(p, 4) for p in precisions],
            'recall': [round(r, 4) for r in recalls],
            'f1_score': [round(f, 4) for f in f1_scores],
            'support': supports
        },
        'macro_avg_metrics': {
            'precision': round(macro_precision, 4),
            'recall': round(macro_recall, 4),
            'f1_score': round(macro_f1, 4),
            'accuracy': round(accuracy, 4)
        },
        'labels': labels
    }

    return results

# Fungsi untuk menghitung KNN dengan Euclidean Distance
def compute_knn_euclidean(train_data, test_data, k):
    try:
        # Ambil semua terms yang unik dari train dan test data
        all_terms = set()
        for data in train_data + test_data:
            if isinstance(data.get('tfidf'), dict):
                all_terms.update(data['tfidf'].keys())
        all_terms = sorted(list(all_terms))
        
        if len(all_terms) == 0:
            return {"error": "No terms found in the data"}
        
        results = []
        
        # Untuk setiap data test
        for i, test_item in enumerate(test_data):
            distances = []
            test_vector = tfidf_to_vector(test_item['tfidf'], all_terms)
            
            # Hitung jarak dengan setiap data train
            for j, train_item in enumerate(train_data):
                train_vector = tfidf_to_vector(train_item['tfidf'], all_terms)
                
                # Hitung euclidean distance
                distance = euclidean_distance(test_vector, train_vector)
                
                distances.append({
                    'train_index': j,
                    'distance': distance,
                    'label': train_item.get('label', 'unknown'),
                    'train_text': train_item.get('text', '')
                })
            
            # Urutkan berdasarkan jarak dan ambil k terdekat
            distances.sort(key=lambda x: x['distance'])
            k_nearest = distances[:k]
            
            # Prediksi berdasarkan mayoritas label
            labels = [neighbor['label'] for neighbor in k_nearest]
            label_count = Counter(labels)
            predicted_label = label_count.most_common(1)[0][0] if label_count else 'unknown'
            
            results.append({
                'test_index': i,
                'test_text': test_item.get('text', ''),
                'actual_label': test_item.get('label', 'unknown'),
                'predicted_label': predicted_label,
                'k_nearest_neighbors': k_nearest,
                'label_distribution': dict(label_count)
            })
        
        return {
            'success': True,
            'results': results,
            'total_terms': len(all_terms),
            'distance_method': 'euclidean'
        }
        
    except Exception as e:
        return {"error": f"Error in KNN computation: {str(e)}"}

# Blueprint untuk route preprocess
preprocess_blueprint = Blueprint('preprocess', __name__)

@preprocess_blueprint.route('/preprocess', methods=['POST'])
def preprocess_route():
    try:
        texts = request.json.get('texts')

        if not texts or not isinstance(texts, list):
            return jsonify({'error': 'Invalid input. Please provide a list of texts.'}), 400

        if not all(isinstance(text, str) for text in texts):
            return jsonify({'error': 'Each item in the list must be a string.'}), 400

        processed_texts = []
        for text in texts:
            processed_text = preprocess_text_step_by_step(text)
            processed_texts.append(processed_text)

        return jsonify({'processed_texts': processed_texts})

    except Exception as e:
        return jsonify({'error': str(e)}), 500

# Blueprint untuk route compute_tfidf
compute_tfidf_blueprint = Blueprint('compute_tfidf', __name__)

@compute_tfidf_blueprint.route('/compute_tfidf', methods=['POST'])
def compute_tfidf_route():
    try:
        texts = request.json.get('texts')

        if not texts or not isinstance(texts, list):
            return jsonify({'error': 'Invalid input. Please provide a list of texts.'}), 400

        if not all(isinstance(text, str) for text in texts):
            return jsonify({'error': 'Each item in the list must be a string.'}), 400

        processed_texts = [preprocess_text_step_by_step(text)['stemmed_text'] for text in texts]
        tfidf_results, idf_dict = compute_tfidf_manual(processed_texts)
        
        final_results = []
        for result in tfidf_results:
            final_results.append({
                'text': result['text'],
                'word_count': result['word_count'],
                'tf': result['tf'],
                'idf': idf_dict,
                'tfidf': result['tfidf'],
                'terms': result['terms']
            })

        return jsonify({'processed_texts': final_results})

    except Exception as e:
        return jsonify({'error': str(e)}), 500

# Blueprint untuk route klasifikasi dengan Euclidean Distance
klasifikasi_blueprint = Blueprint('klasifikasi', __name__)

@klasifikasi_blueprint.route('/klasifikasi', methods=['POST'])
def klasifikasi():
    """
    Endpoint untuk klasifikasi dengan Euclidean Distance dan Confusion Matrix.
    """
    try:
        data = request.json
        
        # Validasi input
        if not data:
            return jsonify({'error': 'No data provided'}), 400
        
        k = data.get('k_value')
        train_data_raw = data.get('train_data')
        test_data_raw = data.get('test_data')
        
        # Validasi parameter
        if k is None:
            return jsonify({'error': 'k_value is required'}), 400
        
        if not train_data_raw or not test_data_raw:
            return jsonify({'error': 'train_data and test_data are required'}), 400
        
        try:
            k = int(k)
            if k <= 0:
                return jsonify({'error': 'k_value must be positive integer'}), 400
        except ValueError:
            return jsonify({'error': 'k_value must be a valid integer'}), 400
        
        if not isinstance(train_data_raw, list) or not isinstance(test_data_raw, list):
            return jsonify({'error': 'train_data and test_data must be lists'}), 400
        
        if len(train_data_raw) == 0:
            return jsonify({'error': 'train_data cannot be empty'}), 400
        
        if k > len(train_data_raw):
            return jsonify({'error': f'k_value ({k}) cannot be greater than number of training samples ({len(train_data_raw)})'}), 400
        
        # Proses data untuk mendapatkan TF-IDF
        train_data = []
        test_data = []
        
        # Kumpulkan semua teks untuk menghitung TF-IDF secara konsisten
        all_texts = []
        train_labels = []
        test_labels = []
        
        # Proses training data
        for i, item in enumerate(train_data_raw):
            if not isinstance(item, dict):
                return jsonify({
                    'error': f'Item {i} in train_data must be a dictionary, got {type(item).__name__}',
                    'item_content': str(item)[:100]
                }), 400
            
            if 'text' not in item:
                return jsonify({
                    'error': f'Item {i} in train_data is missing "text" field',
                    'available_fields': list(item.keys()),
                    'item_content': str(item)[:200]
                }), 400
                
            if 'label' not in item:
                return jsonify({
                    'error': f'Item {i} in train_data is missing "label" field',
                    'available_fields': list(item.keys()),
                    'item_content': str(item)[:200]
                }), 400
            
            # Preprocessing teks
            processed_text = preprocess_text_step_by_step(item['text'])['stemmed_text']
            all_texts.append(processed_text)
            train_labels.append(item['label'])
        
        # Proses test data
        test_original_texts = []
        for i, item in enumerate(test_data_raw):
            if not isinstance(item, dict):
                return jsonify({
                    'error': f'Item {i} in test_data must be a dictionary, got {type(item).__name__}',
                    'item_content': str(item)[:100]
                }), 400
            
            if 'text' not in item:
                return jsonify({
                    'error': f'Item {i} in test_data is missing "text" field',
                    'available_fields': list(item.keys()),
                    'item_content': str(item)[:200]
                }), 400
            
            # Cek apakah ada label untuk test data (untuk confusion matrix)
            test_label = item.get('label', 'unknown')
            test_labels.append(test_label)
            
            # Preprocessing teks
            processed_text = preprocess_text_step_by_step(item['text'])['stemmed_text']
            all_texts.append(processed_text)
            test_original_texts.append(item['text'])
        
        # Hitung TF-IDF untuk semua teks
        if not all_texts or all(len(text.strip()) == 0 for text in all_texts):
            return jsonify({'error': 'No valid text found after preprocessing. Please check your input data.'}), 400
        
        tfidf_results, idf_dict = compute_tfidf_manual(all_texts)
        
        # Pisahkan hasil TF-IDF untuk train dan test data
        train_count = len(train_data_raw)
        
        for i in range(train_count):
            train_data.append({
                'text': train_data_raw[i]['text'],
                'label': train_labels[i],
                'tfidf': tfidf_results[i]['tfidf']
            })
        
        for i in range(len(test_data_raw)):
            test_data.append({
                'text': test_original_texts[i],
                'label': test_labels[i],
                'tfidf': tfidf_results[train_count + i]['tfidf']
            })
        
        # Proses KNN dengan Euclidean Distance
        knn_results = compute_knn_euclidean(train_data, test_data, k)
        
        if 'error' in knn_results:
            return jsonify({'error': knn_results['error']}), 500
        
        # Hitung Confusion Matrix jika ada label yang valid
        confusion_matrix_results = None
        if knn_results['success'] and len(knn_results['results']) > 0:
            # Ambil actual dan predicted labels
            actual_labels = []
            predicted_labels = []
            
            for result in knn_results['results']:
                actual_label = result.get('actual_label', 'unknown')
                predicted_label = result.get('predicted_label', 'unknown')
                
                # Hanya hitung confusion matrix jika ada label yang valid
                if actual_label != 'unknown':
                    actual_labels.append(actual_label)
                    predicted_labels.append(predicted_label)
            
            # Hitung confusion matrix jika ada data yang valid
            if actual_labels and predicted_labels:
                confusion_matrix_results = compute_confusion_matrix(actual_labels, predicted_labels)
        
        return jsonify({
            'success': True,
            'k_value': k,
            'distance_method': 'euclidean',
            'preprocessing_info': {
                'total_train_samples': len(train_data),
                'total_test_samples': len(test_data),
                'total_unique_terms': knn_results.get('total_terms', 0)
            },
            'knn_results': knn_results,
            'confusion_matrix': confusion_matrix_results
        })
    
    except Exception as e:
        return jsonify({'error': f'Unexpected error: {str(e)}'}), 500

# Register blueprints
app.register_blueprint(preprocess_blueprint, url_prefix='/api')
app.register_blueprint(compute_tfidf_blueprint, url_prefix='/api')
app.register_blueprint(klasifikasi_blueprint, url_prefix='/api')

if __name__ == '__main__':
    app.run(debug=True, host='127.0.0.1', port=5000)