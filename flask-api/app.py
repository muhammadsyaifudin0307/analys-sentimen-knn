from flask import Flask, request, jsonify, Blueprint
import re
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory
from Sastrawi.StopWordRemover.StopWordRemoverFactory import StopWordRemoverFactory
from sklearn.feature_extraction.text import TfidfVectorizer
from collections import Counter
from math import log10
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
                line = line.strip()  # Menghapus spasi berlebih di awal dan akhir baris

                # Skip baris kosong atau baris yang tidak mengandung '=>'
                if ' => ' not in line or not line:
                    continue
                
                # Pastikan baris hanya mengandung satu pemisah '=>'
                parts = line.split(' => ')
                if len(parts) != 2:
                    continue  # Abaikan baris yang tidak memiliki dua bagian

                slang, standard = parts
                slang_dict[slang.strip()] = standard.strip()  # Hapus spasi ekstra pada kedua sisi
                
    except FileNotFoundError:
        print("Error: Slang dictionary file not found.")
    return slang_dict

# Load slang dictionary
slang_dict = load_slang_dictionary()

# Fungsi untuk membersihkan teks
def clean_text(text):
    text = re.sub(r'http\S+', '', text)  # Hapus URL
    text = re.sub(r'@\S+', '', text)    # Hapus mention (@username)
    text = re.sub(r'#\S+', '', text)    # Hapus hashtag (#hashtag)
    text = re.sub(r'[^a-zA-Z\s]', '', text)  # Hapus karakter khusus dan angka
    text = re.sub(r'\s+', ' ', text).strip()  # Hapus spasi berlebih
    return text

# Fungsi untuk menggantikan slang dengan kata baku
def normalize_slang(text):
    words = text.split()  # Pisahkan teks menjadi kata
    normalized_words = [slang_dict.get(word, word) for word in words]  # Gantikan slang dengan baku
    return ' '.join(normalized_words)

# Fungsi untuk normalisasi teks sederhana (tanpa translate)
def normalize_text(text):
    """
    Normalisasi teks sederhana - hanya lowercase
    """
    return text.lower()

# Fungsi untuk preprocessing teks (tahapan bertahap) - tanpa translate
def preprocess_text_step_by_step(text):
    # 1. Pembersihan teks
    cleaned_text = clean_text(text)
    
    # 2. Casefolding (mengubah menjadi lowercase)
    casefolded_text = cleaned_text.lower()
    
    # 3. Normalisasi teks sederhana (tanpa translate)
    normalized_text = normalize_text(casefolded_text)
    
    # 4. Normalisasi slang (menggunakan slang dictionary)
    slang_normalized_text = normalize_slang(normalized_text)
    
    # 5. Tokenisasi teks (memisahkan kata)
    tokenized_text = slang_normalized_text.split()
    
    # 6. Hapus stopword
    stopword_removed_text = stopword_remover.remove(' '.join(tokenized_text))
    
    # 7. Stemming (mengubah kata menjadi bentuk dasar)
    stemmed_text = [stemmer.stem(word) for word in stopword_removed_text.split()]
    
    # Gabungkan hasil stemming untuk input ke TF-IDF
    return {
        'cleaned_text': cleaned_text,
        'casefolded_text': casefolded_text,
        'normalized_text': normalized_text,
        'slang_normalized_text': slang_normalized_text,
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

# Fungsi untuk menghitung TF-IDF secara manual
def compute_tfidf_manual(texts):
    """
    Menghitung TF-IDF secara manual dengan konsisten
    """
    # Dapatkan semua unique terms dari semua dokumen
    all_terms = set()
    for text in texts:
        all_terms.update(text.split())
    all_terms = sorted(list(all_terms))
    
    # Hitung TF untuk setiap dokumen
    tf_results = []
    word_counts = []
    for text in texts:
        tf, word_count = compute_tf(text)
        tf_results.append(tf)
        word_counts.append(word_count)
    
    # Hitung IDF
    idf_dict = compute_idf(texts)
    
    # Hitung TF-IDF = TF * IDF
    tfidf_results = []
    for i, text in enumerate(texts):
        tfidf_doc = {}
        tf_doc = tf_results[i]
        
        for term in all_terms:
            tf_value = tf_doc.get(term, 0)  # TF untuk term dalam dokumen ini
            idf_value = idf_dict.get(term, 0)  # IDF untuk term
            tfidf_value = tf_value * idf_value  # TF-IDF = TF * IDF
            tfidf_doc[term] = tfidf_value
        
        tfidf_results.append({
            'text': text,
            'word_count': word_counts[i],
            'tf': tf_doc,
            'tfidf': tfidf_doc,
            'terms': all_terms
        })
    
    return tfidf_results, idf_dict

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

        # Hitung TF-IDF secara manual yang konsisten
        tfidf_results, idf_dict = compute_tfidf_manual(processed_texts)
        
        # Format hasil untuk output
        final_results = []
        for result in tfidf_results:
            final_results.append({
                'text': result['text'],
                'word_count': result['word_count'],
                'tf': result['tf'],
                'idf': idf_dict,  # IDF yang sama untuk semua dokumen
                'tfidf': result['tfidf'],
                'terms': result['terms']
            })

        return jsonify({'processed_texts': final_results})

    except Exception as e:
        return jsonify({'error': str(e)}), 500

# Register blueprints
app.register_blueprint(preprocess_blueprint, url_prefix='/api')
app.register_blueprint(compute_tfidf_blueprint, url_prefix='/api')

if __name__ == '__main__':
    app.run(debug=True, host='127.0.0.1', port=5000)