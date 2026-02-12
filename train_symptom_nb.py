import json, random, re, math
from collections import Counter
from typing import List, Tuple

LABELS = ["FEVER","COLD","COUGH","SORE_THROAT","HEADACHE","STOMACH_PAIN","BODY_PAIN","TIREDNESS"]

SEEDS = {
  "FEVER": ["i have fever","high temperature","feeling hot and chills","temperature is high","fever with body heat","i feel feverish","chills and fever"],
  "COLD": ["runny nose","sneezing a lot","blocked nose","nasal congestion","cold and sneezing","my nose is running","nose blocked"],
  "COUGH": ["i am coughing","dry cough","cough with phlegm","continuous cough","coughing fits","bad cough"],
  "SORE_THROAT": ["throat pain","sore throat","pain while swallowing","itchy throat","throat irritation","throat burning"],
  "HEADACHE": ["headache","head pain","migraine","my head hurts","pain in head","head heavy","head paining"],
  "STOMACH_PAIN": ["stomach pain","abdominal pain","nausea and vomiting","gas and stomach ache","pain in belly","loose motion and stomach pain","stomach burning"],
  "BODY_PAIN": ["body pain","body ache","muscle pain","joint pain","my body is aching","leg pain"],
  "TIREDNESS": ["i feel tired","fatigue and weakness","very weak","low energy","tiredness whole day","feeling exhausted"]
}

EXTRA_CONTEXT = [
  "since morning","from yesterday","for two days","after travelling","after eating",
  "at night","with slight chills","and feeling weak","and i cannot sleep","and i feel stressed"
]

def normalize_text(s: str) -> str:
  # keep unicode letters/numbers, replace punctuation with space
  s = s.lower()
  s = re.sub(r"[^\w\s]", " ", s, flags=re.UNICODE)
  s = re.sub(r"\s+", " ", s).strip()
  return s

def feats(text: str) -> List[str]:
  t = normalize_text(text)
  if not t:
    return []
  tokens = t.split()
  out = []
  for w in tokens:
    if len(w) >= 2:
      out.append("w:"+w)
    # char 3-grams + 4-grams
    for n in (3,4):
      if len(w) >= n:
        for i in range(0, len(w)-n+1):
          out.append(f"g{n}:"+w[i:i+n])
  return out

def typo_word(w: str) -> str:
  if len(w) < 4 or random.random() < 0.55:
    return w
  op = random.choice(["del","swap","dup","replace"])
  i = random.randint(0, len(w)-1)
  if op == "del" and len(w) > 4:
    return w[:i] + w[i+1:]
  if op == "swap" and i < len(w)-1:
    return w[:i] + w[i+1] + w[i] + w[i+2:]
  if op == "dup":
    return w[:i] + w[i] + w[i:]
  if op == "replace":
    ch = random.choice("abcdefghijklmnopqrstuvwxyz")
    return w[:i] + ch + w[i+1:]
  return w

def make_examples(n_per_label=900) -> Tuple[List[str], List[str]]:
  X, y = [], []
  for lab in LABELS:
    base = SEEDS[lab]
    for _ in range(n_per_label):
      s = random.choice(base)
      if random.random() < 0.85:
        s += " " + random.choice(EXTRA_CONTEXT)
      words = [typo_word(w) for w in s.split()]
      X.append(" ".join(words))
      y.append(lab)
  # multi-symptom examples: keep them but later treat as "top2"
  for _ in range(600):
    a, b = random.sample(LABELS, 2)
    s = random.choice(SEEDS[a]) + " and " + random.choice(SEEDS[b])
    if random.random() < 0.85:
      s += " " + random.choice(EXTRA_CONTEXT)
    X.append(s)
    y.append(a)
  return X, y

def train_nb(X, y, max_vocab=2000, alpha=1.0):
  feat_counts = Counter()
  for text in X:
    feat_counts.update(feats(text))
  vocab = [f for f,_ in feat_counts.most_common(max_vocab)]
  vid = {f:i for i,f in enumerate(vocab)}

  class_counts = Counter(y)
  total_docs = len(y)

  token_counts = {lab: [0]*len(vocab) for lab in LABELS}
  total_tokens = {lab: 0 for lab in LABELS}

  for text, lab in zip(X, y):
    c = Counter(feats(text))
    for f, cnt in c.items():
      j = vid.get(f)
      if j is None: continue
      token_counts[lab][j] += cnt
      total_tokens[lab] += cnt

  log_prior, log_prob, unk_log_prob = [], [], []
  for lab in LABELS:
    prior = (class_counts[lab] + 1.0) / (total_docs + len(LABELS))
    log_prior.append(math.log(prior))

    denom = total_tokens[lab] + alpha*len(vocab)
    row = []
    for j in range(len(vocab)):
      p = (token_counts[lab][j] + alpha) / denom
      row.append(math.log(p))
    log_prob.append(row)

    p_unk = alpha / denom
    unk_log_prob.append(math.log(p_unk))

  return {
    "labels": LABELS,
    "vocab": vocab,
    "log_prior": log_prior,
    "log_prob": log_prob,
    "unk_log_prob": unk_log_prob,
    "version": 2
  }

def predict(model, text):
  labels = model["labels"]
  vocab = model["vocab"]
  vid = {f:i for i,f in enumerate(vocab)}
  log_prior = model["log_prior"]
  log_prob = model["log_prob"]
  unk = model["unk_log_prob"]

  c = Counter(feats(text))
  scores = []
  for ci, lab in enumerate(labels):
    s = log_prior[ci]
    denom_row = log_prob[ci]
    for f, cnt in c.items():
      j = vid.get(f)
      s += cnt * (denom_row[j] if j is not None else unk[ci])
    scores.append((s, lab))
  scores.sort(reverse=True)
  return scores[0][1]

if __name__ == "__main__":
  random.seed(7)
  X, y = make_examples(n_per_label=900)

  # shuffle + split
  idx = list(range(len(X)))
  random.shuffle(idx)
  split = int(0.85 * len(idx))
  tr, te = idx[:split], idx[split:]

  Xtr = [X[i] for i in tr]; ytr = [y[i] for i in tr]
  Xte = [X[i] for i in te]; yte = [y[i] for i in te]

  model = train_nb(Xtr, ytr, max_vocab=2200, alpha=1.0)

  correct = 0
  for x, yt in zip(Xte, yte):
    yp = predict(model, x)
    if yp == yt: correct += 1
  acc = correct / len(yte)

  print("Test accuracy:", round(acc, 4), "on", len(yte), "samples")

  with open("symptom_model_nb_v2.json", "w", encoding="utf-8") as f:
    json.dump(model, f)

  print("✅ Saved: symptom_model_nb_v2.json")
  print("Vocab size:", len(model["vocab"]))
