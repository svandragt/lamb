.PHONY: docs

# Preview the end-user docs locally at http://localhost:4000/lamb/
docs:
	cd docs && bundle install && bundle exec jekyll serve
