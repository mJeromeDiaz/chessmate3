r"""
Reference vectors of App\Repertoire\Srs\Fsrs, generated with py-fsrs (the reference FSRS-6
implementation). Not run by the tests: they read fsrs-vectors.json.

    PYTHONPATH=<unpacked fsrs-6.3.2 wheel> python3 fsrs-vectors.py > fsrs-vectors.json

Default parameters, desired retention 0.9, learning steps 1 min and 10 min, relearning step
10 min, maximum interval 36500 days, no fuzzing.
"""
import json
import random
from datetime import datetime, timedelta, timezone

import fsrs
from fsrs import Card, Rating, Scheduler

scheduler = Scheduler(enable_fuzzing=False)
START = datetime(2026, 1, 1, 9, 0, 0, tzinfo=timezone.utc)


def snapshot(card):
    return {
        "state": card.state.value,
        "step": card.step,
        "stability": card.stability,
        "difficulty": card.difficulty,
        "due": card.due.isoformat(),
        "lastReview": card.last_review.isoformat() if card.last_review else None,
    }


def run(name, reviews):
    """reviews: list of (seconds after the previous review or after START, rating)."""
    card = Card(card_id=1, due=START)
    at = START
    steps = []
    for delay, rating in reviews:
        at = at + timedelta(seconds=delay)
        retrievability = scheduler.get_card_retrievability(card, at)
        card, _ = scheduler.review_card(card, Rating(rating), at)
        steps.append({"at": at.isoformat(), "rating": rating, "retrievability": retrievability, "card": snapshot(card)})
    return {"name": name, "steps": steps}


MIN, HOUR, DAY = 60, 3600, 86400
cases = [
    run("good through the learning steps", [(0, 3), (MIN, 3), (10 * MIN, 3), (3 * DAY, 3), (12 * DAY, 3)]),
    run("easy at once", [(0, 4), (15 * DAY, 4), (60 * DAY, 4)]),
    run("hard at the first step", [(0, 2), (5 * MIN, 2), (5 * MIN, 3), (10 * MIN, 3)]),
    run("again in learning", [(0, 1), (MIN, 1), (MIN, 3), (10 * MIN, 3)]),
    run("lapse and relearning", [(0, 3), (MIN, 3), (10 * MIN, 3), (4 * DAY, 1), (10 * MIN, 2), (10 * MIN, 3), (2 * DAY, 3)]),
    run("same-day reviews in review state", [(0, 4), (2 * HOUR, 3), (3 * HOUR, 2), (5 * HOUR, 1), (10 * MIN, 3)]),
    run("very late review", [(0, 3), (MIN, 3), (10 * MIN, 3), (400 * DAY, 3), (3 * DAY, 4)]),
    run("rounding of 23 h 59", [(0, 4), (DAY - 1, 3), (DAY + 1, 3)]),
]
rng = random.Random(20260930)
for n in range(40):
    reviews = [(0, rng.choice([1, 2, 3, 4]))]
    for _ in range(rng.randint(4, 14)):
        delay = rng.choice([rng.randint(30, 20 * MIN), rng.randint(HOUR, 30 * HOUR), rng.randint(DAY, 120 * DAY)])
        reviews.append((delay, rng.choices([1, 2, 3, 4], weights=[2, 2, 5, 2])[0]))
    cases.append(run(f"random {n}", reviews))

print(json.dumps({"generator": f"py-fsrs {fsrs.__version__ if hasattr(fsrs, '__version__') else '6.3.2'}", "parameters": list(scheduler.parameters), "cases": cases}, indent=1))
