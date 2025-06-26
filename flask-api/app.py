from flask import Flask, request, jsonify, Blueprint
import re
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory
from Sastrawi.StopWordRemover.StopWordRemoverFactory import StopWordRemoverFactory
from sklearn.feature_extraction.text import TfidfVectorizer
from collections import Counter
from math import log10

# Inisialisasi Flask app
app = Flask(__name__)

# Inisialisasi Stopword Remover dan Stemmer dari Sastrawi
factory_stopword = StopWordRemoverFactory()
stopword_remover = factory_stopword.create_stop_word_remover()

factory_stemmer = StemmerFactory()
stemmer = factory_stemmer.create_stemmer()

# Fungsi untuk membersihkan teks
def clean_text(text):
    text = re.sub(r'http\S+', '', text)  # Hapus URL
    text = re.sub(r'@\S+', '', text)    # Hapus mention (@username)
    text = re.sub(r'#\S+', '', text)    # Hapus hashtag (#hashtag)
    text = re.sub(r'[^a-zA-Z\s]', '', text)  # Hapus karakter khusus dan angka
    text = re.sub(r'\s+', ' ', text).strip()  # Hapus spasi berlebih
    return text

# Fungsi untuk preprocessing teks (tahapan bertahap)
def preprocess_text_step_by_step(text):
    # 1. Pembersihan teks
    cleaned_text = clean_text(text)
    
    # 2. Casefolding (mengubah menjadi lowercase)
    casefolded_text = cleaned_text.lower()
    
    # 3. Normalisasi (menghapus spasi ekstra)
    normalized_text = re.sub(r'\s+', ' ', casefolded_text).strip()
    
    # 4. Tokenisasi teks (memisahkan kata)
    tokenized_text = normalized_text.split()
    
    # 5. Hapus stopword
    stopword_removed_text = stopword_remover.remove(' '.join(tokenized_text))
    
    # 6. Stemming (mengubah kata menjadi bentuk dasar)
    stemmed_text = [stemmer.stem(word) for word in stopword_removed_text.split()]
    
    # Gabungkan hasil stemming untuk input ke TF-IDF
    return {
        'cleaned_text': cleaned_text,
        'casefolded_text': casefolded_text,
        'normalized_text': normalized_text,
        'tokenized_text': tokenized_text,
        'stopword_removed_text': stopword_removed_text,
        'stemmed_text': " ".join(stemmed_text)  # Ensure it's a string for TF-IDF
    }

# Fungsi untuk menghitung Term Frequency (TF) yang sudah dinormalisasi
def compute_tf(text):
    word_count = Counter(text.split())
    total_words = len(text.split())
    
    # Normalized Term Frequency (TF) = (count of term in document) / (total words in document)
    tf = {word: count / total_words for word, count in word_count.items()}
    return tf, word_count  # Return both normalized TF and raw word count

# Fungsi untuk menghitung Document Frequency (DF) dan IDF
def compute_idf(texts):
    N = len(texts)  # Jumlah total dokumen (N)
    df_dict = {}  # Document Frequency dictionary

    # Hitung DF untuk setiap kata
    for text in texts:
        words_in_text = set(text.split())  # Menggunakan set untuk menghitung hanya dokumen unik
        for word in words_in_text:
            if word in df_dict:
                df_dict[word] += 1
            else:
                df_dict[word] = 1

    # Hitung IDF menggunakan rumus log10
    idf_dict = {word: log10(N / df) for word, df in df_dict.items()}
    return idf_dict

# Fungsi untuk menghitung TF-IDF
def compute_tfidf(texts):
    vectorizer = TfidfVectorizer()
    tfidf_matrix = vectorizer.fit_transform(texts)
    tfidf_scores = tfidf_matrix.toarray()
    feature_names = vectorizer.get_feature_names_out()

    # Menghitung IDF secara manual menggunakan log10
    idf_dict = compute_idf(texts)

    # Mengembalikan hasil IDF dan TF-IDF
    return tfidf_scores.tolist(), feature_names, idf_dict

# Blueprint untuk route preprocess
preprocess_blueprint = Blueprint('preprocess', __name__)

@preprocess_blueprint.route('/preprocess', methods=['POST'])
def preprocess_route():
    try:
        texts = request.json.get('texts')

        # Validasi input
        if not texts or not isinstance(texts, list):
            return jsonify({'error': 'Invalid input. Please provide a list of texts.'}), 400

        if not all(isinstance(text, str) for text in texts):
            return jsonify({'error': 'Each item in the list must be a string.'}), 400

        # Proses setiap teks dan kirimkan hasil setiap tahapan
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
        # Menerima input teks yang sudah diproses dari /preprocess
        texts = request.json.get('texts')

        # Validasi input
        if not texts or not isinstance(texts, list):
            return jsonify({'error': 'Invalid input. Please provide a list of texts.'}), 400

        if not all(isinstance(text, str) for text in texts):
            return jsonify({'error': 'Each item in the list must be a string.'}), 400

        # Proses setiap teks yang sudah diproses dari /preprocess
        processed_texts = [preprocess_text_step_by_step(text)['stemmed_text'] for text in texts]

        tf_results = []
        word_counts = []
        for text in processed_texts:
            tf, word_count = compute_tf(text)
            tf_results.append(tf)
            word_counts.append(word_count)

        # Hitung TF-IDF
        tfidf_scores, feature_names, idf_dict = compute_tfidf(processed_texts)

        # Menyusun hasil akhir
        tfidf_result = []
        for i, text in enumerate(processed_texts):
            word_tfidf = {feature_names[j]: tfidf_scores[i][j] for j in range(len(feature_names))}
            tf_result = tf_results[i]
            terms_list = list(feature_names)

            tfidf_result.append({
                'text': text,
                'word_count': word_counts[i],  # Mengirimkan word count
                'tf': tf_result,
                'idf': idf_dict,
                'tfidf': word_tfidf,
                'terms': terms_list,
            })
        print(f"TF-IDF Results: {tfidf_result}")  
        return jsonify({'processed_texts': tfidf_result})

    except Exception as e:
        return jsonify({'error': str(e)}), 500

# Register blueprints
app.register_blueprint(preprocess_blueprint, url_prefix='/api')
app.register_blueprint(compute_tfidf_blueprint, url_prefix='/api')

if __name__ == '__main__':
    app.run(debug=True, host='127.0.0.1', port=5000)
