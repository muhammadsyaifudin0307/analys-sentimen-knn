from flask import Flask, request, jsonify
import re
from Sastrawi.Stemmer.StemmerFactory import StemmerFactory
from Sastrawi.StopWordRemover.StopWordRemoverFactory import StopWordRemoverFactory

app = Flask(__name__)

# Inisialisasi Stopword Remover dan Stemmer dari Sastrawi
factory_stopword = StopWordRemoverFactory()
stopword_remover = factory_stopword.create_stop_word_remover()

factory_stemmer = StemmerFactory()
stemmer = factory_stemmer.create_stemmer()

# Fungsi untuk membersihkan teks
def clean_text(text):
    # Hapus URL
    text = re.sub(r'http\S+', '', text)
    # Hapus mention (@username)
    text = re.sub(r'@\S+', '', text)
    # Hapus hashtag (#hashtag)
    text = re.sub(r'#\S+', '', text)
    # Hapus karakter khusus dan angka
    text = re.sub(r'[^a-zA-Z\s]', '', text)
    # Hapus spasi berlebih
    text = re.sub(r'\s+', ' ', text).strip()
    return text

# Fungsi preprocessing untuk teks
def preprocess_text(text):
    # Bersihkan teks menggunakan clean_text
    cleaned_text = clean_text(text)
    
    # Casefolding: mengubah teks menjadi huruf kecil
    casefolding = cleaned_text.lower()

    # Normalisasi: menghilangkan spasi ekstra
    normalisasi = re.sub(r'\s+', ' ', cleaned_text).strip()

    # Tokenisasi: memecah teks menjadi kata-kata
    tokenization = cleaned_text.split()

    # Stopword removal menggunakan Sastrawi
    stopword = stopword_remover.remove(' '.join(tokenization))  # Menghapus stopword
    stopword = stopword.split()  # Mengubah hasilnya menjadi array

    # Stemming menggunakan Sastrawi
    stemming = [stemmer.stem(word) for word in stopword]  # Proses stemming untuk setiap kata

    return {
        'cleaning': cleaned_text,
        'casefolding': casefolding,
        'normalisasi': normalisasi,
        'tokenization': tokenization,  # Menyimpan tokenization sebagai array
        'stopword': stopword,          # Menyimpan hasil stopword sebagai array
        'stemming': stemming          # Menyimpan hasil stemming sebagai array
    }

@app.route('/preprocess', methods=['POST'])
def preprocess():
    texts = request.json.get('texts')  # Mendapatkan teks dalam list
    
    # Validasi: memastikan teks yang dikirim adalah list dan tidak kosong
    if not texts or not isinstance(texts, list):
        return jsonify({'error': 'Invalid input. Please provide a list of texts.'}), 400
    
    # Memastikan bahwa setiap elemen dalam list adalah string
    if not all(isinstance(text, str) for text in texts):
        return jsonify({'error': 'Each item in the list must be a string.'}), 400

    # Melakukan preprocessing untuk setiap teks
    processed_texts = [preprocess_text(text) for text in texts]
    
    # Mengembalikan hasil preprocessing dalam format JSON
    return jsonify({'processed_texts': processed_texts})

if __name__ == '__main__':
    app.run(debug=True)
