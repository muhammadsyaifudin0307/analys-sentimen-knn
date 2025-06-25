from flask import Flask, request, jsonify, Blueprint
import re
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory
from Sastrawi.StopWordRemover.StopWordRemoverFactory import StopWordRemoverFactory
from sklearn.feature_extraction.text import TfidfVectorizer
from collections import Counter

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

# Fungsi preprocessing untuk teks
def preprocess_text(text):
    cleaned_text = clean_text(text)
    casefolding = cleaned_text.lower()
    normalisasi = re.sub(r'\s+', ' ', cleaned_text).strip()
    tokenization = cleaned_text.split()
    stopword = stopword_remover.remove(' '.join(tokenization)).split()
    stemming = [stemmer.stem(word) for word in stopword]
    
    return ' '.join(stemming)  # Gabungkan hasil stemming untuk input ke TF-IDF

# Fungsi untuk menghitung Term Frequency (TF) yang sudah dinormalisasi
def compute_tf(text):
    # Tokenisasi teks dan hitung frekuensi kata
    word_count = Counter(text.split())
    total_words = len(text.split())
    
    # Normalisasi TF (Frekuensi kata dibagi total kata)
    tf = {word: count / total_words for word, count in word_count.items()}
    return tf

# Fungsi untuk menghitung TF-IDF
def compute_tfidf(texts):
    vectorizer = TfidfVectorizer()
    tfidf_matrix = vectorizer.fit_transform(texts)
    
    # Mengonversi sparse matrix menjadi array biasa
    tfidf_scores = tfidf_matrix.toarray()
    
    # Mengonversi ndarray menjadi list agar bisa diserialisasi ke JSON
    tfidf_scores_list = tfidf_scores.tolist()
    
    # Mengambil nama fitur (kata-kata yang ada di corpus)
    feature_names = vectorizer.get_feature_names_out()
    
    # Menghitung IDF (Inverse Document Frequency)
    idf = vectorizer.idf_
    idf_dict = {feature_names[i]: idf[i] for i in range(len(feature_names))}
    
    return tfidf_scores_list, feature_names, idf_dict


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

        # Preprocess the text
        processed_texts = [preprocess_text(text) for text in texts]
        
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

        # Proses TF-IDF
        processed_texts = [preprocess_text(text) for text in texts]
        
        tf_results = [compute_tf(text) for text in processed_texts]
        tfidf_scores, feature_names, idf_dict = compute_tfidf(processed_texts)
        
        # Format TF-IDF yang sesuai
        tfidf_result = []
        for i, text in enumerate(processed_texts):
            word_tfidf = {feature_names[j]: tfidf_scores[i][j] for j in range(len(feature_names))}
            tf_result = tf_results[i]
            terms_list = feature_names.tolist()  # Mengubah feature_names menjadi list

            tfidf_result.append({
                'text': text,
                'tf': tf_result,
                'tfidf': word_tfidf,
                'idf': idf_dict,  # Mengirimkan IDF juga
                'terms': terms_list  # Gunakan list Python biasa
            })

        # Menambahkan debug untuk melihat hasil yang dikirimkan
        print("Final TF-IDF response:", tfidf_result)

        # Pastikan format yang dikirimkan sesuai dan valid untuk PHP
        return jsonify({'processed_texts': tfidf_result})

    except Exception as e:
        return jsonify({'error': str(e)}), 500


# Register blueprints
app.register_blueprint(preprocess_blueprint, url_prefix='/api')
app.register_blueprint(compute_tfidf_blueprint, url_prefix='/api')


if __name__ == '__main__':
    app.run(debug=True, host='127.0.0.1', port=5000)
